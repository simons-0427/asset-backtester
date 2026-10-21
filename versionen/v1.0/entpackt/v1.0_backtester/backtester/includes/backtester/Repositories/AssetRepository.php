<?php

declare(strict_types=1);

namespace Smstark\Backtester\Repositories;

use PDO;
use Smstark\Backtester\Domain\AssetDefinition;

final class AssetRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function active(): array
    {
        $statement = $this->pdo->query($this->baseQuery() . ' ORDER BY a.sort_order, a.id');
        return array_map([AssetDefinition::class, 'fromRow'], $statement->fetchAll());
    }

    public function bySlugs(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $statement = $this->pdo->prepare(
            $this->baseQuery() . " AND a.slug IN ($placeholders) ORDER BY a.sort_order, a.id"
        );
        $statement->execute(array_values($slugs));
        return array_map([AssetDefinition::class, 'fromRow'], $statement->fetchAll());
    }

    public function dueForSync(int $limit = 20): array
    {
        $statement = $this->pdo->prepare(
            $this->baseQuery()
            . ' AND (ss.next_check_at IS NULL OR ss.next_check_at <= UTC_TIMESTAMP())'
            . ' AND (ss.lock_until IS NULL OR ss.lock_until < UTC_TIMESTAMP())'
            . " AND a.provider_symbol NOT LIKE 'CONFIGURE\\_%'"
            . ' ORDER BY COALESCE(ss.next_check_at, \'1970-01-01\'), a.sort_order LIMIT :limit'
        );
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();
        return array_map([AssetDefinition::class, 'fromRow'], $statement->fetchAll());
    }

    public function bySlug(string $slug): ?AssetDefinition
    {
        $assets = $this->bySlugs([$slug]);
        return $assets[0] ?? null;
    }

    private function baseQuery(): string
    {
        return <<<'SQL'
SELECT
    a.*,
    ds.slug AS source_slug,
    ds.display_name AS source_name,
    ds.provider_type,
    ds.base_url AS source_base_url,
    ds.license_note,
    ds.attribution_text,
    (SELECT MIN(o1.observation_date) FROM backtester_observations o1 WHERE o1.asset_id = a.id) AS first_date,
    (SELECT MAX(o2.observation_date) FROM backtester_observations o2 WHERE o2.asset_id = a.id) AS last_date,
    ss.last_successful_sync_at
FROM backtester_assets a
INNER JOIN backtester_data_sources ds ON ds.id = a.source_id AND ds.is_active = 1
LEFT JOIN backtester_sync_state ss ON ss.asset_id = a.id
WHERE a.is_active = 1
SQL;
    }
}
