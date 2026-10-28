<?php

declare(strict_types=1);

namespace Smstark\Weltmarkt\Import;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class JobCatalog
{
    private const JOBS = [
        'markets_daily' => [
            'label' => 'Börsen, Rohstoffe, Volatilität und Devisen',
            'frequency' => 'daily',
            'collector' => 'collectMarkets',
            'lookback' => '-21 days',
            'weekdays' => [2, 3, 4, 5, 6],
            'local_time' => '02:00:00',
        ],
        'rates_daily' => [
            'label' => 'Staatsanleiherenditen und Credit Spread',
            'frequency' => 'daily',
            'collector' => 'collectYields',
            'lookback' => '-21 days',
            'weekdays' => [2, 3, 4, 5, 6],
            'local_time' => '02:05:00',
        ],
        'policy_daily' => [
            'label' => 'Leitzinsen (tägliche Reihen)',
            'frequency' => 'daily',
            'collector' => 'collectPolicyRates',
            'lookback' => '-45 days',
            'weekdays' => [2, 3, 4, 5, 6],
            'local_time' => '02:10:00',
        ],
        'fed_balance_weekly' => [
            'label' => 'Bilanzsumme der Federal Reserve',
            'frequency' => 'weekly',
            'collector' => 'collectFedBalance',
            'lookback' => '-90 days',
            'weekday' => 6,
            'local_time' => '02:15:00',
        ],
        'monthly_early' => [
            'label' => 'PMI, Konsumklima und frühe Monatsdaten',
            'frequency' => 'monthly',
            'collector' => 'collectMonthlyEarly',
            'lookback' => '-18 months',
            'day' => 7,
            'local_time' => '02:20:00',
        ],
        'monthly_mid' => [
            'label' => 'Arbeitsmarkt, Inflation, Industrie und Konsum',
            'frequency' => 'monthly',
            'collector' => 'collectMonthlyMid',
            'lookback' => '-24 months',
            'day' => 17,
            'local_time' => '02:25:00',
        ],
        'monthly_late' => [
            'label' => 'BIP, Welthandel, PBoC-LPR, Notenbankbilanzen und Bewertung',
            'frequency' => 'monthly',
            'collector' => 'collectMonthlyLate',
            'lookback' => '-36 months',
            'day' => 28,
            'local_time' => '02:30:00',
        ],
    ];

    public function all(): array
    {
        $jobs = [];
        foreach (self::JOBS as $key => $definition) {
            $jobs[$key] = ['key' => $key] + $definition;
        }

        return $jobs;
    }

    public function get(string $key): array
    {
        $jobs = $this->all();
        if (!isset($jobs[$key])) {
            throw new InvalidArgumentException('Unbekannter Weltmarkt-Importjob: ' . $key);
        }

        return $jobs[$key];
    }

    public function nextScheduledAt(array $job, DateTimeImmutable $after, string $timezone): DateTimeImmutable
    {
        $localZone = new DateTimeZone($timezone);
        $utc = new DateTimeZone('UTC');
        $local = $after->setTimezone($localZone);
        $time = (string) ($job['local_time'] ?? '02:00:00');

        if ($job['frequency'] === 'daily') {
            $candidate = $local->modify('+1 day')->setTime(...$this->timeParts($time));
            $weekdays = array_map('intval', (array) ($job['weekdays'] ?? []));
            while ($weekdays !== [] && !in_array((int) $candidate->format('N'), $weekdays, true)) {
                $candidate = $candidate->modify('+1 day');
            }
        } elseif ($job['frequency'] === 'weekly') {
            $weekday = (int) ($job['weekday'] ?? 6);
            $candidate = $local->modify('+1 day')->setTime(...$this->timeParts($time));
            while ((int) $candidate->format('N') !== $weekday) {
                $candidate = $candidate->modify('+1 day');
            }
        } elseif ($job['frequency'] === 'monthly') {
            $day = (int) ($job['day'] ?? 1);
            $firstNextMonth = $local->modify('first day of next month')->setTime(...$this->timeParts($time));
            $lastDay = (int) $firstNextMonth->format('t');
            $candidate = $firstNextMonth->setDate(
                (int) $firstNextMonth->format('Y'),
                (int) $firstNextMonth->format('m'),
                min($day, $lastDay),
            );
        } else {
            throw new InvalidArgumentException('Nicht unterstützte Importfrequenz.');
        }

        return $candidate->setTimezone($utc);
    }

    private function timeParts(string $time): array
    {
        $parts = array_map('intval', explode(':', $time));
        return [$parts[0] ?? 2, $parts[1] ?? 0, $parts[2] ?? 0];
    }
}
