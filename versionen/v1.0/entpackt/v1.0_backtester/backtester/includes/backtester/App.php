<?php

declare(strict_types=1);

namespace Smstark\Backtester;

use PDO;
use Smstark\Backtester\Http\RequestValidator;
use Smstark\Backtester\Providers\BitcoinProvider;
use Smstark\Backtester\Providers\CsvImportProvider;
use Smstark\Backtester\Providers\GoldProvider;
use Smstark\Backtester\Providers\ProviderRegistry;
use Smstark\Backtester\Providers\Sp500Provider;
use Smstark\Backtester\Repositories\AssetRepository;
use Smstark\Backtester\Repositories\ObservationRepository;
use Smstark\Backtester\Repositories\SyncRepository;
use Smstark\Backtester\Services\BacktestService;
use Smstark\Backtester\Services\Logger;
use Smstark\Backtester\Services\SummaryService;
use Smstark\Backtester\Services\SyncService;
use Smstark\Backtester\Services\ValidationService;

final class App
{
    private ?PDO $pdo = null;

    public function __construct(public readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= Database::connect($this->config['database']);
    }

    public function assets(): AssetRepository
    {
        return new AssetRepository($this->pdo());
    }

    public function observations(): ObservationRepository
    {
        return new ObservationRepository($this->pdo());
    }

    public function syncRepository(): SyncRepository
    {
        return new SyncRepository($this->pdo());
    }

    public function backtests(): BacktestService
    {
        return new BacktestService($this->observations());
    }

    public function summaries(): SummaryService
    {
        return new SummaryService($this->observations());
    }

    public function validator(): RequestValidator
    {
        return new RequestValidator($this->config['app']);
    }

    public function providers(): ProviderRegistry
    {
        return new ProviderRegistry([
            new Sp500Provider(
                $this->config['providers']['fred'] ?? [],
                $this->config['providers']['ecb'] ?? [],
            ),
            new BitcoinProvider($this->config['providers']['coingecko']),
            new GoldProvider(
                $this->config['providers']['alpha_vantage'],
                $this->config['providers']['ecb'] ?? [],
            ),
            new CsvImportProvider($this->config['providers']['csv']),
        ]);
    }

    public function syncService(): SyncService
    {
        return new SyncService(
            $this->assets(),
            $this->observations(),
            $this->syncRepository(),
            $this->providers(),
            new ValidationService(),
            new Logger($this->config['sync']['log_file']),
            $this->config['sync'],
        );
    }
}
