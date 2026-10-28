<?php

declare(strict_types=1);

namespace Smstark\Weltmarkt;

use PDO;
use Smstark\Weltmarkt\Import\HttpClient;
use Smstark\Weltmarkt\Import\JobCatalog;
use Smstark\Weltmarkt\Import\SyncService;
use Smstark\Weltmarkt\Repositories\MetricRepository;
use Smstark\Weltmarkt\Repositories\ObservationRepository;
use Smstark\Weltmarkt\Repositories\SyncRepository;

final class App
{
    private ?PDO $pdo = null;

    public function __construct(private readonly array $config)
    {
    }

    public function metrics(): MetricRepository
    {
        return new MetricRepository($this->pdo());
    }

    public function observations(): ObservationRepository
    {
        return new ObservationRepository($this->pdo());
    }

    public function syncState(): SyncRepository
    {
        return new SyncRepository($this->pdo());
    }

    public function imports(): SyncService
    {
        $settings = $this->config['imports'] ?? [];
        $settings['timezone'] = (string) ($this->config['app']['timezone'] ?? 'Europe/Berlin');
        return new SyncService(
            $this->syncState(),
            new JobCatalog(),
            new HttpClient(
                (int) ($settings['http_timeout_seconds'] ?? 12),
                (int) ($settings['max_response_bytes'] ?? 25_000_000),
                (string) ($settings['user_agent'] ?? 'smstark-weltmarkt-import/1.0'),
                is_array($settings['allowed_hosts'] ?? null) ? $settings['allowed_hosts'] : [],
            ),
            $settings,
        );
    }

    public function importSettings(): array
    {
        return is_array($this->config['imports'] ?? null) ? $this->config['imports'] : [];
    }

    public function maxRangeDays(): int
    {
        return (int) ($this->config['limits']['max_range_days'] ?? 7000);
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = Database::connect($this->config['database'] ?? []);
        }

        return $this->pdo;
    }
}
