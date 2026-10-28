<?php

declare(strict_types=1);

namespace Smstark\Backtester\Providers;

use DateTimeImmutable;
use Smstark\Backtester\Contracts\HistoricalDataProviderInterface;
use Smstark\Backtester\Domain\AssetDefinition;
use Smstark\Backtester\Domain\HistoricalObservation;
use Smstark\Backtester\Exceptions\ProviderException;

final class GoldProvider implements HistoricalDataProviderInterface
{
    private readonly GoldApiProvider $delegate;
    private readonly UsdToEurConverter $converter;
    private readonly float $calibrationRatio;
    private readonly string $calibrationReferenceDate;

    public function __construct(array $goldApiConfig, array $ecbConfig = [])
    {
        $this->delegate = new GoldApiProvider($goldApiConfig);
        $this->converter = new UsdToEurConverter(new EcbExchangeRateProvider($ecbConfig));
        $ratio = trim((string) ($goldApiConfig['gld_xau_ratio'] ?? ''));
        if (!is_numeric($ratio) || (float) $ratio <= 0 || (float) $ratio >= 1) {
            throw new ProviderException(
                'Für GoldAPI muss ein positives GLD/XAU-Kalibrierungsverhältnis zwischen 0 und 1 konfiguriert sein.',
                'PROVIDER_CONFIG_INVALID',
            );
        }
        $this->calibrationRatio = (float) $ratio;
        $referenceDate = trim((string) ($goldApiConfig['calibration_reference_date'] ?? ''));
        $parsedReferenceDate = DateTimeImmutable::createFromFormat('!Y-m-d', $referenceDate);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (
            $parsedReferenceDate === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $parsedReferenceDate->format('Y-m-d') !== $referenceDate
        ) {
            throw new ProviderException('Das GoldAPI-Kalibrierungsdatum ist ungültig.', 'PROVIDER_CONFIG_INVALID');
        }
        $this->calibrationReferenceDate = $referenceDate;
    }

    public function fetchRange(AssetDefinition $asset, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->converter->convert($this->calibrate($this->delegate->fetchRange($asset, $from, $to)));
    }

    public function fetchRecent(AssetDefinition $asset, int $lookbackDays): array
    {
        return $this->converter->convert($this->calibrate($this->delegate->fetchRecent($asset, $lookbackDays)));
    }

    public function supports(AssetDefinition $asset): bool
    {
        return $asset->slug === 'gold' && $asset->providerType === 'goldapi';
    }

    /**
     * GoldAPI liefert XAU/USD. Die bestehende Datenreihe bleibt als
     * GLD-ähnlicher USD-Vergleichswert erhalten, indem nur zukünftige Werte
     * mit dem fixierten Referenzverhältnis kalibriert werden.
     *
     * @param HistoricalObservation[] $observations
     * @return HistoricalObservation[]
     */
    private function calibrate(array $observations): array
    {
        $calibrated = [];
        foreach ($observations as $observation) {
            if (!$observation instanceof HistoricalObservation) {
                throw new ProviderException('GoldAPI lieferte eine ungültige Beobachtung.', 'INVALID_OBSERVATION');
            }
            $value = (float) $observation->value * $this->calibrationRatio;
            $ratioText = rtrim(rtrim(number_format($this->calibrationRatio, 12, '.', ''), '0'), '.');
            $revision = substr(
                (string) $observation->sourceRevision
                    . ';gld-xau-ratio:' . $ratioText
                    . '@' . $this->calibrationReferenceDate,
                0,
                100,
            );
            $calibrated[] = new HistoricalObservation(
                $observation->date,
                rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.'),
                'USD',
                $observation->sourceTimestamp,
                $observation->qualityStatus,
                $revision,
                hash('sha256', implode('|', [
                    (string) $observation->rawPayloadHash,
                    $ratioText,
                ])),
            );
        }
        return $calibrated;
    }
}
