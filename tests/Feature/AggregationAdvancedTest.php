<?php

namespace DartVadius\EloquentSearch\Tests\Feature;

use DartVadius\EloquentSearch\Exceptions\InvalidPayloadException;
use DartVadius\EloquentSearch\Searchable;
use DartVadius\EloquentSearch\SearchableConfig;
use DartVadius\EloquentSearch\SearchQuery;
use DartVadius\EloquentSearch\SearchServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;

/**
 * Covers v1.5.0 aggregation: multi-metric, multi-groupBy, expression metrics/dimensions,
 * PHP derived metrics, HAVING, grand total. Singular `metric`/`groupBy` stays BC (AggregationTest).
 */
class AggregationAdvancedTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SearchServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__ . '/../migrations');
        $this->seedData();
    }

    protected function seedData(): void
    {
        // status: active=3 (Alice,Bob,Diana), inactive=2 (Charlie,Eve)
        // category_id: Alice=1, Bob=2, Charlie=1, Diana=3, Eve=2  → sum=9
        // cat parity (id % 2): 1→1, 2→0, 3→1
        AdvAggModel::create(['name' => 'Alice', 'status' => 'active', 'category_id' => 1]);
        AdvAggModel::create(['name' => 'Bob', 'status' => 'active', 'category_id' => 2]);
        AdvAggModel::create(['name' => 'Charlie', 'status' => 'inactive', 'category_id' => 1]);
        AdvAggModel::create(['name' => 'Diana', 'status' => 'active', 'category_id' => 3]);
        AdvAggModel::create(['name' => 'Eve', 'status' => 'inactive', 'category_id' => 2]);
    }

    private function agg(array $spec, array $payload = []): array
    {
        return SearchQuery::aggregate(AdvAggModel::query(), array_merge($payload, ['aggregate' => $spec]));
    }

    /** index grouped rows by their scalar `group` for order-independent assertions */
    private function byGroup(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (array_key_exists('group', $r) && ! is_array($r['group'])) {
                $out[$r['group']] = $r;
            }
        }
        return $out;
    }

    // ── 1. multi-metric ──────────────────────────────────────────────────────

    public function test_multi_metric_no_group_returns_values_map(): void
    {
        $rows = $this->agg(['metrics' => [
            ['fn' => 'count', 'as' => 'c'],
            ['fn' => 'sum', 'field' => 'category_id', 'as' => 's'],
        ]]);

        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('group', $rows[0]);
        $this->assertArrayNotHasKey('value', $rows[0]);
        $this->assertEquals(['c' => 5, 's' => 9], $rows[0]['values']);
    }

    public function test_multi_metric_grouped(): void
    {
        $rows = $this->byGroup($this->agg([
            'metrics' => [
                ['fn' => 'count', 'as' => 'c'],
                ['fn' => 'sum', 'field' => 'category_id', 'as' => 's'],
            ],
            'groupBy' => ['field' => 'status'],
        ]));

        $this->assertEquals(['c' => 3, 's' => 6], $rows['active']['values']);
        $this->assertEquals(['c' => 2, 's' => 3], $rows['inactive']['values']);
    }

    public function test_metric_as_defaults(): void
    {
        $rows = $this->agg(['metrics' => [
            ['fn' => 'count'],
            ['fn' => 'sum', 'field' => 'category_id'],
        ]]);

        $this->assertEquals(['count' => 5, 'sum_category_id' => 9], $rows[0]['values']);
    }

    // ── 2. multi-groupBy ─────────────────────────────────────────────────────

    public function test_multi_group_by_returns_group_object(): void
    {
        $rows = $this->agg([
            'metrics' => [['fn' => 'count', 'as' => 'c']],
            'groupBy' => [['field' => 'status'], ['field' => 'cat_parity']],
        ]);

        // 4 combos: active/1 (Alice,Diana)=2, active/0 (Bob)=1, inactive/1 (Charlie)=1, inactive/0 (Eve)=1
        $this->assertCount(4, $rows);
        foreach ($rows as $r) {
            $this->assertIsArray($r['group']);
            $this->assertArrayHasKey('status', $r['group']);
            $this->assertArrayHasKey('cat_parity', $r['group']);
        }
        $find = function ($status, $parity) use ($rows) {
            foreach ($rows as $r) {
                if ($r['group']['status'] === $status && (int) $r['group']['cat_parity'] === $parity) {
                    return $r['values']['c'];
                }
            }
            return null;
        };
        $this->assertEquals(2, $find('active', 1));
        $this->assertEquals(1, $find('active', 0));
        $this->assertEquals(1, $find('inactive', 1));
        $this->assertEquals(1, $find('inactive', 0));
    }

    // ── 3. expression metric (E) ─────────────────────────────────────────────

    public function test_expression_metric_grouped(): void
    {
        $rows = $this->byGroup($this->agg([
            'metrics' => [['name' => 'active_cnt', 'as' => 'a']],
            'groupBy' => ['field' => 'status'],
        ]));

        $this->assertEquals(3, $rows['active']['values']['a']);
        $this->assertEquals(0, $rows['inactive']['values']['a']);
    }

    // ── 4. expression dimension (period escape hatch, portable) ──────────────

    public function test_expression_dimension_group_by(): void
    {
        $rows = $this->agg([
            'metrics' => [['fn' => 'count', 'as' => 'c']],
            'groupBy' => ['field' => 'cat_parity'],
        ]);

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['group']] = $r['values']['c'];
        }
        // parity 1: cat 1,1,3 (Alice,Charlie,Diana)=3 ; parity 0: cat 2,2 (Bob,Eve)=2
        $this->assertEquals([1 => 3, 0 => 2], $map);
    }

    // ── 5. derived metric (PHP) ──────────────────────────────────────────────

    public function test_derived_metric_no_group(): void
    {
        $rows = $this->agg(['metrics' => [['name' => 'active_rate', 'as' => 'rate']]]);

        // 100 * active_cnt(3) / cnt(5) = 60
        $this->assertEquals(['rate' => 60], $rows[0]['values']);
        // base metrics pulled in only to feed the closure must NOT leak into output
        $this->assertArrayNotHasKey('cnt', $rows[0]['values']);
        $this->assertArrayNotHasKey('active_cnt', $rows[0]['values']);
    }

    public function test_derived_metric_grouped(): void
    {
        $rows = $this->byGroup($this->agg([
            'metrics' => [['name' => 'active_rate', 'as' => 'rate']],
            'groupBy' => ['field' => 'status'],
        ]));

        $this->assertEquals(100, $rows['active']['values']['rate']);
        $this->assertEquals(0, $rows['inactive']['values']['rate']);
    }

    // ── 6. HAVING (A: incl. between, derived-aware) ──────────────────────────

    public function test_having_gt_on_metric(): void
    {
        $rows = $this->agg([
            'metrics' => [['fn' => 'count', 'as' => 'c']],
            'groupBy' => ['field' => 'category_id'],
            'having' => [['metric' => 'c', 'op' => 'gt', 'value' => 1]],
        ]);

        // counts: cat1=2, cat2=2, cat3=1 → keep cat1,cat2
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertGreaterThan(1, $r['values']['c']);
        }
    }

    public function test_having_between(): void
    {
        $rows = $this->agg([
            'metrics' => [['fn' => 'count', 'as' => 'c']],
            'groupBy' => ['field' => 'category_id'],
            'having' => [['metric' => 'c', 'op' => 'between', 'value' => [2, 2]]],
        ]);

        $this->assertCount(2, $rows); // cat1,cat2
    }

    public function test_having_on_derived_metric(): void
    {
        $rows = $this->agg([
            'metrics' => [['name' => 'active_rate', 'as' => 'rate']],
            'groupBy' => ['field' => 'status'],
            'having' => [['metric' => 'rate', 'op' => 'gte', 'value' => 50]],
        ]);

        $this->assertCount(1, $rows); // active(100) kept, inactive(0) dropped
        $this->assertEquals('active', $rows[0]['group']);
    }

    // ── ordering + top-N ─────────────────────────────────────────────────────

    public function test_order_by_metric_desc_limit(): void
    {
        $rows = $this->agg([
            'metrics' => [['fn' => 'count', 'as' => 'c']],
            'groupBy' => ['field' => 'category_id'],
            'orderBy' => 'c',
            'direction' => 'desc',
            'limit' => 1,
        ]);

        $this->assertCount(1, $rows);
        $this->assertEquals(2, $rows[0]['values']['c']); // top count
    }

    public function test_order_by_group_asc(): void
    {
        $rows = $this->agg([
            'metrics' => [['fn' => 'count', 'as' => 'c']],
            'groupBy' => ['field' => 'category_id'],
            'orderBy' => 'group',
            'direction' => 'asc',
        ]);

        $this->assertEquals([1, 2, 3], array_map(fn ($r) => (int) $r['group'], $rows));
    }

    public function test_order_by_derived_metric_php_path(): void
    {
        $rows = $this->agg([
            'metrics' => [['name' => 'active_rate', 'as' => 'rate']],
            'groupBy' => ['field' => 'status'],
            'orderBy' => 'rate',
            'direction' => 'desc',
        ]);

        $this->assertEquals('active', $rows[0]['group']);   // 100
        $this->assertEquals('inactive', $rows[1]['group']); // 0
    }

    // ── total (C: re-aggregation, not row-sum) ───────────────────────────────

    public function test_grand_total_appended(): void
    {
        $rows = $this->agg([
            'metrics' => [
                ['fn' => 'count', 'as' => 'c'],
                ['fn' => 'sum', 'field' => 'category_id', 'as' => 's'],
            ],
            'groupBy' => ['field' => 'status'],
            'total' => true,
        ]);

        $total = end($rows);
        $this->assertNull($total['group']);
        $this->assertEquals(['c' => 5, 's' => 9], $total['values']);
    }

    public function test_grand_total_derived_is_reaggregated_not_averaged(): void
    {
        $rows = $this->agg([
            'metrics' => [['name' => 'active_rate', 'as' => 'rate']],
            'groupBy' => ['field' => 'status'],
            'total' => true,
        ]);

        $total = end($rows);
        $this->assertNull($total['group']);
        // re-aggregation over the whole set: 100*3/5 = 60 (NOT avg(100,0)=50)
        $this->assertEquals(60, $total['values']['rate']);
    }

    // ── validation ───────────────────────────────────────────────────────────

    public function test_rejects_unknown_named_metric(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->agg(['metrics' => [['name' => 'nope']]]);
    }

    public function test_rejects_metric_entry_without_fn_or_name(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->agg(['metrics' => [['as' => 'x']]]);
    }

    public function test_rejects_having_unknown_metric(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->agg([
            'metrics' => [['fn' => 'count', 'as' => 'c']],
            'groupBy' => ['field' => 'status'],
            'having' => [['metric' => 'ghost', 'op' => 'gt', 'value' => 1]],
        ]);
    }

    public function test_rejects_having_bad_operator(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->agg([
            'metrics' => [['fn' => 'count', 'as' => 'c']],
            'groupBy' => ['field' => 'status'],
            'having' => [['metric' => 'c', 'op' => 'like', 'value' => 1]],
        ]);
    }

    public function test_rejects_group_by_depth_over_cap(): void
    {
        config()->set('eloquent-search.limits.max_group_by_depth', 2);

        $this->expectException(InvalidPayloadException::class);
        $this->agg([
            'metrics' => [['fn' => 'count', 'as' => 'c']],
            'groupBy' => [['field' => 'status'], ['field' => 'category_id'], ['field' => 'cat_parity']],
        ]);
    }

    public function test_rejects_unknown_expression_dimension(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->agg([
            'metrics' => [['fn' => 'count', 'as' => 'c']],
            'groupBy' => ['field' => 'not_a_dimension'],
        ]);
    }

    // ── filters still apply through the rich path ────────────────────────────

    public function test_filters_apply_with_multi_metric(): void
    {
        $rows = $this->agg(
            ['metrics' => [['fn' => 'count', 'as' => 'c']], 'groupBy' => ['field' => 'category_id']],
            ['where' => ['eq' => ['status' => 'active']]]
        );

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['group']] = $r['values']['c'];
        }
        $this->assertEquals([1 => 1, 2 => 1, 3 => 1], $map); // active: Alice(1),Bob(2),Diana(3)
    }
}

class AdvAggModel extends Model
{
    use Searchable;

    protected $table = 'test_models';
    protected $guarded = [];

    protected $casts = [
        'category_id' => 'integer',
    ];

    public function searchableConfig(): SearchableConfig
    {
        return SearchableConfig::make()
            ->fields(['id', 'name', 'status', 'category_id'])
            ->metrics([
                'category_id' => ['sum', 'avg', 'min', 'max'],                       // column metric (fn + field)
                'cnt' => ['expr' => DB::raw('count(*)')],                             // expression metric (E)
                'active_cnt' => ['expr' => DB::raw("sum(case when status = 'active' then 1 else 0 end)")],
            ])
            ->dimensions([
                'status', 'category_id',                                             // column dimensions
                'cat_parity' => DB::raw('(category_id % 2)'),                        // expression dimension (escape hatch)
            ])
            ->derived([
                'active_rate' => fn ($v) => ($v['cnt'] ?? 0) > 0
                    ? round(100 * ($v['active_cnt'] ?? 0) / $v['cnt'], 2) : 0,
            ])
            ->sortable(['id', 'name'])
            ->defaultSort('name', 'asc');
    }
}
