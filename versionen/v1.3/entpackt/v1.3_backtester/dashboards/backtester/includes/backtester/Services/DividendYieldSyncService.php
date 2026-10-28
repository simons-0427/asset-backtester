<?php

declare(strict_types=1);

namespace Smstark\Backtester\Services;

use DateTimeImmutable;
use DateTimeZone;
use Smstark\Backtester\Domain\DividendYieldObservation;
use Smstark\Backtester\Exceptions\ProviderException;
use Smstark\Backtester\Providers\MultplDividendYieldProvider;
use Smstark\Backtester\Repositories\AssetRepository;
use Smstark\Backtester\Repositories\DividendYieldRepository;

final class DividendYieldSyncService
{
    public function __construct(
        private readonly AssetRepository $assets,
        private readonly DividendYieldRepository $repository,
        private readonly MultplDividendYieldProvider $provider,
        private readonly Logger $logger,
        private readonly array $config = [],
    ) {
    }

    public function syncDue(bool $force = false): array
    {
        if (!(bool) ($this->config['enabled'] ?? true)) {
            return ['status' => 'skipped', 'reason' => 'disabled'];
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sourceSlug = (string) ($this->config['source_slug'] ?? 'multpl-sp500-dividend-yield');
        if (!$force && !$this->repository->isDue($sourceSlug, $now)) {
            return ['status' => 'skipped', 'reason' => 'not_due'];
        }

        $recentMonths = max(1, (int) ($this->config['recent_months'] ?? 3));
        $from = $now
            ->modify(sprintf('-%d months', $recentMonths - 1))
            ->modify('first day of this month')
            ->setTime(0, 0);
        return $this->execute($from, false, $now);
    }

    public function backfill(DateTimeImmutable $from): array
    {
        return $this->execute($from->modify('first day of this month')->setTime(0, 0), true);
    }

    private function execute(
        DateTimeImmutable $from,
        bool $isBackfill,
        ?DateTimeImmutable $now = null,
    ): array {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sourceSlug = (string) ($this->config['source_slug'] ?? 'multpl-sp500-dividend-yield');
        $asset = $this->assets->bySlug('sp500');
        if ($asset === null) {
            return $this->failure($sourceSlug, 'ASSET_NOT_FOUND', 'Das S&P-500-Asset wurde nicht gefunden.', $now);
        }

        try {
            $all = $this->provider->fetchAll();
            $currentMonth = $now->format('Y-m-01');
            $selected = array_values(array_filter(
                $all,
                static fn (DividendYieldObservation $row): bool =>
                    $row->observationMonth() >= $from
                    && $row->observationMonth()->format('Y-m-01') <= $currentMonth,
            ));
            if ($selected === []) {
                throw new ProviderException(
                    'Multpl lieferte für den angeforderten Zeitraum keine Monatswerte.',
                    'PROVIDER_EMPTY_RESPONSE',
                );
            }

            $counts = $this->repository->upsertMany($asset->id, $selected);
            $latest = end($selected);
            $latestMonth = $latest->observationMonth()->format('Y-m-d');
            $nextCheck = $now->modify('first day of next month')->setTime(0, 0);
            $this->repository->markSuccess($sourceSlug, $latestMonth, $nextCheck);
            $this->logger->info('Multpl dividend yield sync succeeded.', [
                'mode' => $isBackfill ? 'backfill' : 'monthly',
                'latest_month' => $latestMonth,
                'rows_inserted' => $counts['inserted'],
                'rows_updated' => $counts['updated'],
                'rows_unchanged' => $counts['unchanged'],
            ]);

            return [
                'status' => 'success',
                'mode' => $isBackfill ? 'backfill' : 'monthly',
                'latest_observation_month' => $latestMonth,
                'rows_inserted' => $counts['inserted'],
                'rows_updated' => $counts['updated'],
                'rows_unchanged' => $counts['unchanged'],
                'contains_estimates' => count(array_filter(
                    $selected,
                    static fn (DividendYieldObservation $row): bool => $row->isEstimate,
                )) > 0,
                'next_check_at' => $nextCheck->format(DATE_ATOM),
            ];
        } catch (\Throwable $error) {
            $code = $error instanceof ProviderException ? $error->providerCode : 'DIVIDEND_SYNC_FAILED';
            return $this->failure($sourceSlug, $code, $error->getMessage(), $now);
        }
    }

    private function failure(
        string $sourceSlug,
        string $code,
        string $message,
        DateTimeImmutable $now,
    ): array {
        $failureCount = $this->repository->failureCount($sourceSlug);
        $retryHours = min(24, 2 ** min(4, $failureCount));
        $nextCheck = $now->modify(sprintf('+%d hours', max(1, $retryHours)));
        $this->repository->markFailure($sourceSlug, $code, $message, $nextCheck);
        $this->logger->error('Multpl dividend yield sync failed.', [
            'error_code' => $code,
            'message' => $message,
            'next_check_at' => $nextCheck->format(DATE_ATOM),
        ]);
        return [
            'status' => 'failed',
            'error_code' => $code,
            'public_error' => 'Die monatliche Dividendenrendite konnte nicht aktualisiert werden.',
            'next_check_at' => $nextCheck->format(DATE_ATOM),
        ];
    }
}
