<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use DateTimeImmutable;
use DateTimeZone;
use Smstark\Backtester\Domain\HistoricalObservation;
use Smstark\Backtester\Exceptions\ProviderException;

final class UsdToEurConverter
{
    public function __construct(private readonly EcbExchangeRateProvider $exchangeRates)
    {
    }

    /**
     * @param HistoricalObservation[] $observations
     * @return HistoricalObservation[]
     */
    public function convert(array $observations): array
    {
        if ($observations === []) {
            return [];
        }

        usort($observations, static fn (HistoricalObservation $a, HistoricalObservation $b): int => $a->date <=> $b->date);
        $from = $observations[0]->date->modify('-10 days');
        $to = $observations[array_key_last($observations)]->date;
        $rates = $this->exchangeRates->fetchUsdPerEur($from, $to);
        return self::convertWithRates($observations, $rates);
    }

    /**
     * @param HistoricalObservation[] $observations
     * @param array<string, string> $usdPerEurByDate
     * @return HistoricalObservation[]
     */
    public static function convertWithRates(array $observations, array $usdPerEurByDate): array
    {
        if ($observations === []) {
            return [];
        }
        if ($usdPerEurByDate === []) {
            throw new ProviderException('Für die USD-Reihe fehlen EZB-Wechselkurse.', 'FX_RATE_MISSING');
        }

        ksort($usdPerEurByDate);
        usort($observations, static fn (HistoricalObservation $a, HistoricalObservation $b): int => $a->date <=> $b->date);
        $rateDates = array_keys($usdPerEurByDate);
        $cursor = 0;
        $currentRateDate = null;
        $currentUsdPerEur = null;
        $converted = [];
        $utc = new DateTimeZone('UTC');

        foreach ($observations as $observation) {
            if (!$observation instanceof HistoricalObservation || strtoupper($observation->currency) !== 'USD') {
                throw new ProviderException('Die EUR-Umrechnung erwartet eine USD-Beobachtung.', 'CURRENCY_MISMATCH');
            }
            $marketDate = $observation->date->format('Y-m-d');
            while ($cursor < count($rateDates) && $rateDates[$cursor] <= $marketDate) {
                $currentRateDate = $rateDates[$cursor];
                $currentUsdPerEur = $usdPerEurByDate[$currentRateDate];
                $cursor++;
            }
            if ($currentRateDate === null || !is_numeric($currentUsdPerEur) || (float) $currentUsdPerEur <= 0) {
                throw new ProviderException('Vor ' . $marketDate . ' fehlt ein verwendbarer EZB-Referenzkurs.', 'FX_RATE_MISSING');
            }

            $rateToEur = 1 / (float) $currentUsdPerEur;
            $eurValue = (float) $observation->value * $rateToEur;
            $hashInput = implode('|', [
                (string) $observation->rawPayloadHash,
                $currentRateDate,
                (string) $currentUsdPerEur,
            ]);
            $revision = (string) $observation->sourceRevision . ';ecb-usd-eur:' . $currentRateDate;
            $converted[] = new HistoricalObservation(
                $observation->date,
                $observation->value,
                'USD',
                $observation->sourceTimestamp,
                $observation->qualityStatus,
                substr($revision, 0, 100),
                hash('sha256', $hashInput),
                self::decimal($eurValue, 10),
                self::decimal($rateToEur, 12),
                new DateTimeImmutable($currentRateDate, $utc),
            );
        }
        return $converted;
    }

    private static function decimal(float $value, int $scale): string
    {
        return rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.');
    }
}
