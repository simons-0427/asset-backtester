<?php

declare(strict_types=1);

namespace Smstark\Backtester;

use PDO;
use Smstark\Backtester\Http\RequestValidator;
use Smstark\Backtester\Providers\BitcoinProvider;
use Smstark\Backtester\Providers\BlsInflationProvider;
use Smstark\Backtester\Providers\CsvImportProvider;
use Smstark\Backtester\Providers\EcbCompoundedIndexProvider;
use Smstark\Backtester\Providers\EurostatHousePriceProvider;
use Smstark\Backtester\Providers\EurostatInflationProvider;
use Smstark\Backtester\Providers\FredCsvProvider;
use Smstark\Backtester\Providers\GoldProvider;
use Smstark\Backtester\Providers\MsciWorldProvider;
use Smstark\Backtester\Providers\MultplDividendYieldProvider;
use Smstark\Backtester\Providers\NyFedSofrIndexProvider;
use Smstark\Backtester\Providers\ProviderRegistry;
use Smstark\Backtester\Providers\Sp500Provider;
use Smstark\Backtester\Providers\UsRealEstateProvider;
use Smstark\Backtester\Providers\YahooFundProvider;
use Smstark\Backtester\Providers\YahooIndexProvider;
use Smstark\Backtester\Repositories\AssetRepository;
use Smstark\Backtester\Repositories\DividendYieldRepository;
use Smstark\Backtester\Repositories\ObservationRepository;
use Smstark\Backtester\Repositories\SyncRepository;
use Smstark\Backtester\Services\BacktestService;
use Smstark\Backtester\Services\DividendYieldSyncService;
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

    public function dividendYields(): DividendYieldRepository
    {
        return new DividendYieldRepository($this->pdo());
    }

    public function syncRepository(): SyncRepository
    {
        return new SyncRepository($this->pdo());
    }

    public function backtests(): BacktestService
    {
        return new BacktestService(
            $this->observations(),
            $this->dividendYields(),
            (bool) ($this->config['features']['dividend_adjustments'] ?? true),
        );
    }

    public function summaries(): SummaryService
    {
        return new SummaryService(
            $this->observations(),
            $this->dividendYields(),
            (bool) ($this->config['features']['dividend_adjustments'] ?? true),
        );
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
                $this->config['providers']['goldapi'] ?? [],
                $this->config['providers']['ecb'] ?? [],
            ),
            new MsciWorldProvider(
                $this->config['providers']['msci_world'] ?? [],
                $this->config['providers']['ecb'] ?? [],
            ),
            new YahooIndexProvider(
                $this->config['providers']['yahoo_indices']
                    ?? $this->config['providers']['msci_world']
                    ?? [],
            ),
            new EurostatHousePriceProvider(
                $this->config['providers']['eurostat'] ?? [],
            ),
            new YahooFundProvider(
                $this->config['providers']['market_proxies']
                    ?? $this->config['providers']['msci_world']
                    ?? [],
                $this->config['providers']['ecb'] ?? [],
            ),
            new EcbCompoundedIndexProvider(
                $this->config['providers']['ecb_money_market']
                    ?? $this->config['providers']['ecb']
                    ?? [],
            ),
            new NyFedSofrIndexProvider(
                $this->config['providers']['nyfed'] ?? [],
                $this->config['providers']['ecb'] ?? [],
            ),
            new EurostatInflationProvider(
                $this->config['providers']['eurostat'] ?? [],
            ),
            new BlsInflationProvider(
                $this->config['providers']['bls'] ?? [],
            ),
            new FredCsvProvider($this->config['providers']['fred_csv'] ?? []),
            new UsRealEstateProvider($this->config['providers']['fred'] ?? []),
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

    public function dividendYieldSyncService(): DividendYieldSyncService
    {
        $config = $this->config['providers']['multpl_dividend_yield'] ?? [];
        return new DividendYieldSyncService(
            $this->assets(),
            $this->dividendYields(),
            new MultplDividendYieldProvider($config),
            new Logger($this->config['sync']['log_file']),
            $config,
        );
    }

    public function publicStatus(): array
    {
        $status = $this->syncRepository()->publicStatus();
        $sourceSlug = (string) (
            $this->config['providers']['multpl_dividend_yield']['source_slug']
            ?? 'multpl-sp500-dividend-yield'
        );
        $status['dividendYield'] = $this->dividendYields()->publicStatus($sourceSlug);
        return $status;
    }
}
