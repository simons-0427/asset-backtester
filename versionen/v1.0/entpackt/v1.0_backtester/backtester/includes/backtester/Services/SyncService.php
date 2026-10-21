<?php

declare(strict_types=1);

namespace Smstark\Backtester\Services;

use DateTimeImmutable;
use DateTimeZone;
use Smstark\Backtester\Domain\AssetDefinition;
use Smstark\Backtester\Exceptions\ProviderException;
use Smstark\Backtester\Providers\ProviderRegistry;
use Smstark\Backtester\Repositories\AssetRepository;
use Smstark\Backtester\Repositories\ObservationRepository;
use Smstark\Backtester\Repositories\SyncRepository;

final class SyncService
{
    public function __construct(
        private readonly AssetRepository $assets,
        private readonly ObservationRepository $observations,
        private readonly SyncRepository $sync,
        private readonly ProviderRegistry $providers,
        private readonly ValidationService $validation,
        private readonly Logger $logger,
        private readonly array $config,
    ) {
    }

    public function syncDue(string $triggerType = 'cron', ?int $limit = null): array
    {
        $assets = $this->assets->dueForSync($limit ?? 20);
        return $this->syncAssets($assets, $triggerType, null, null);
    }

    public function syncAssets(
        array $assets,
        string $triggerType,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to
    ): array {
        $runId = $this->sync->startRun($triggerType);
        $stats = [
            'run_id' => $runId,
            'assets_checked' => 0,
            'assets_succeeded' => 0,
            'assets_failed' => 0,
            'assets_locked' => 0,
            'rows_inserted' => 0,
            'rows_updated' => 0,
            'rows_unchanged' => 0,
        ];
        $errors = [];

        foreach ($assets as $asset) {
            if (!$asset instanceof AssetDefinition) {
                continue;
            }
            $stats['assets_checked']++;
            $result = $this->syncOne($asset, $from, $to, $triggerType);
            if ($result['status'] === 'locked') {
                $stats['assets_locked']++;
                continue;
            }
            if ($result['status'] === 'success') {
                $stats['assets_succeeded']++;
                $stats['rows_inserted'] += $result['inserted'];
                $stats['rows_updated'] += $result['updated'];
                $stats['rows_unchanged'] += $result['unchanged'];
            } else {
                $stats['assets_failed']++;
                $errors[] = ['asset' => $asset->slug, 'code' => $result['code']];
            }
        }

        $this->sync->finishRun($runId, $stats, $errors);
        $stats['errors'] = $errors;
        return $stats;
    }

    private function syncOne(
        AssetDefinition $asset,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        string $triggerType
    ): array {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $token = self::uuidV4();
        $lockUntil = $now->modify(sprintf('+%d seconds', (int) $this->config['lock_seconds']));
        if (!$this->sync->acquireLock($asset->id, $token, $lockUntil)) {
            return ['status' => 'locked'];
        }

        try {
            $provider = $this->providers->forAsset($asset);
            if ($from !== null && $to !== null) {
                $raw = $provider->fetchRange($asset, $from, $to);
            } else {
                $raw = $provider->fetchRecent($asset, (int) $this->config['overlap_days']);
            }
            if ($raw === []) {
                throw new ProviderException('Der Provider hat keine Beobachtungen geliefert.', 'EMPTY_PROVIDER_RESPONSE');
            }

            $validated = $this->validation->validate($raw, $asset);
            $counts = $this->observations->upsertMany($asset->id, $validated);
            $lastObservation = end($validated);
            $latest = $lastObservation->date->format('Y-m-d');
            $next = SyncPolicy::nextSuccessCheck($now, (int) $this->config['success_check_delay_hours']);
            $cursor = $triggerType === 'initial_backfill' ? $latest : null;
            $this->sync->markSuccess($asset->id, $latest, $next, $cursor);
            $this->logger->info('Asset synchronisiert.', [
                'asset' => $asset->slug,
                'inserted' => $counts['inserted'],
                'updated' => $counts['updated'],
                'unchanged' => $counts['unchanged'],
            ]);
            return ['status' => 'success'] + $counts;
        } catch (\Throwable $error) {
            $code = $error instanceof ProviderException ? $error->providerCode : 'SYNC_FAILED';
            $failures = $this->sync->failureCount($asset->id) + 1;
            $next = SyncPolicy::nextFailureCheck($now, $failures, (int) $this->config['failure_backoff_max_hours']);
            $this->sync->markFailure($asset->id, $code, $error->getMessage(), $next);
            $this->logger->error('Asset-Synchronisation fehlgeschlagen.', [
                'asset' => $asset->slug,
                'code' => $code,
                'error' => $error->getMessage(),
            ]);
            return ['status' => 'failed', 'code' => $code];
        } finally {
            $this->sync->releaseLock($asset->id, $token);
        }
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
