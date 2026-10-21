<?php

declare(strict_types=1);

use Smstark\Backtester\Exceptions\ValidationException;
use Smstark\Backtester\Http\JsonResponse;

require_once dirname(__DIR__, 2) . '/includes/backtester/autoload.php';

try {
    $app = require dirname(__DIR__, 2) . '/includes/backtester/bootstrap.php';
    $activeAssets = $app->assets()->active();
    $allowedSlugs = array_map(
        static fn ($asset): string => $asset->slug,
        array_values(array_filter($activeAssets, static fn ($asset): bool => $asset->assetClass !== 'inflation')),
    );
    $request = $app->validator()->series($_GET, $allowedSlugs);
    $assets = $app->assets()->bySlugs($request['assets']);
    $inflationAsset = $request['inflationSlug'] === null
        ? null
        : $app->assets()->bySlug($request['inflationSlug']);
    if ($request['inflationSlug'] !== null && ($inflationAsset === null || $inflationAsset->assetClass !== 'inflation')) {
        throw new ValidationException('Die gewählte Inflationsreihe ist nicht verfügbar.', 'INFLATION_UNAVAILABLE');
    }
    $usNetRentalCashflowPct = $request['includeRentalCashflow']
        ? $request['netRentalCashflowPct']
        : 0.0;
    $deNetRentalCashflowPct = $request['includeRentalCashflow']
        ? $request['deNetRentalCashflowPct']
        : 0.0;
    $result = $app->backtests()->run(
        $assets,
        $request['start'],
        $request['end'],
        $request['amount'],
        $usNetRentalCashflowPct,
        $deNetRentalCashflowPct,
        $inflationAsset,
        $request['customInflationPct'],
        $request['includeTer'] ? $request['terPct'] : 0.0,
        $request['taxSettings'],
    );

    JsonResponse::send([
        'success' => true,
        'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
        'request' => [
            'assets' => $request['assets'],
            'amount' => $request['amount'],
            'netRentPct' => $usNetRentalCashflowPct,
            'deNetRentPct' => $deNetRentalCashflowPct,
            'includeRentalCashflow' => $request['includeRentalCashflow'],
            'inflation' => $request['inflationSlug'] ?? ($request['customInflationPct'] !== null ? 'custom' : 'none'),
            'customInflationPct' => $request['customInflationPct'],
            'includeTer' => $request['includeTer'],
            'terPct' => $request['includeTer'] ? $request['terPct'] : 0.0,
            'taxes' => $request['taxSettings'],
            'start' => $request['start']->format('Y-m-d'),
            'end' => $request['end']->format('Y-m-d'),
            'currency' => 'EUR',
        ],
        'commonCalendar' => $result['commonCalendar'],
        'series' => $result['series'],
        'warnings' => $result['warnings'],
        'dataStatus' => $app->publicStatus(),
    ], 200, 300);
} catch (ValidationException $error) {
    JsonResponse::error($error->errorCode, $error->getMessage(), 422);
} catch (Throwable $error) {
    error_log('Backtester series endpoint: ' . $error->getMessage());
    JsonResponse::error('SERVICE_UNAVAILABLE', 'Die gespeicherten Backtestdaten sind vorübergehend nicht verfügbar.', 503);
}
