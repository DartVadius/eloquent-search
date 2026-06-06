<?php

namespace DartVadius\EloquentSearch\Tests\Feature;

use DartVadius\EloquentSearch\Exceptions\InvalidPayloadException;
use DartVadius\EloquentSearch\Searchable;
use DartVadius\EloquentSearch\SearchableConfig;
use DartVadius\EloquentSearch\SearchQuery;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase;
use DartVadius\EloquentSearch\SearchServiceProvider;

class AggregationTest extends TestCase
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
        // category_id: 1=2 (Alice,Charlie), 2=2 (Bob,Eve), 3=1 (Diana)  → sum=9
        AggTestModel::create(['name' => 'Alice', 'status' => 'active', 'category_id' => 1, 'scheduled_at' => '2026-04-01 10:00:00']);
        AggTestModel::create(['name' => 'Bob', 'status' => 'active', 'category_id' => 2, 'scheduled_at' => '2026-04-05 14:00:00']);
        AggTestModel::create(['name' => 'Charlie', 'status' => 'inactive', 'category_id' => 1, 'scheduled_at' => '2026-05-10 08:00:00']);
        AggTestModel::create(['name' => 'Diana', 'status' => 'active', 'category_id' => 3, 'scheduled_at' => null]);
        AggTestModel::create(['name' => 'Eve', 'status' => 'inactive', 'category_id' => 2, 'scheduled_at' => '2026-04-15 16:00:00']);
    }

    private function agg(array $spec, array $payload = []): array
    {
        // primary usage: the whole payload (filters + aggregate block) goes into the builder
        return SearchQuery::aggregate(AggTestModel::query(), array_merge($payload, ['aggregate' => $spec]));
    }

    /** value-by-group map for order-independent assertions */
    private function map(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r['group']] = $r['value'];
        }
        return $out;
    }

    // ── count ──

    public function test_count_no_group_returns_single_total(): void
    {
        $rows = $this->agg(['metric' => ['fn' => 'count']]);

        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('group', $rows[0]);
        $this->assertEquals(5, $rows[0]['value']);
    }

    public function test_count_grouped_by_categorical(): void
    {
        $rows = $this->agg(['metric' => ['fn' => 'count'], 'groupBy' => ['field' => 'status']]);

        $this->assertEquals(['active' => 3, 'inactive' => 2], $this->map($rows));
    }

    public function test_count_grouped_by_integer_dimension(): void
    {
        $rows = $this->agg(['metric' => ['fn' => 'count'], 'groupBy' => ['field' => 'category_id']]);

        $this->assertEquals([1 => 2, 2 => 2, 3 => 1], $this->map($rows));
    }

    // ── sum / avg / min / max ──

    public function test_sum_grouped(): void
    {
        $rows = $this->agg(['metric' => ['fn' => 'sum', 'field' => 'category_id'], 'groupBy' => ['field' => 'status']]);

        // active: 1+2+3=6, inactive: 1+2=3
        $this->assertEquals(['active' => 6, 'inactive' => 3], $this->map($rows));
    }

    public function test_avg_no_group(): void
    {
        $rows = $this->agg(['metric' => ['fn' => 'avg', 'field' => 'category_id']]);

        // (1+2+1+3+2)/5 = 1.8
        $this->assertEqualsWithDelta(1.8, $rows[0]['value'], 0.001);
    }

    public function test_min_max_grouped(): void
    {
        $min = $this->map($this->agg(['metric' => ['fn' => 'min', 'field' => 'category_id'], 'groupBy' => ['field' => 'status']]));
        $max = $this->map($this->agg(['metric' => ['fn' => 'max', 'field' => 'category_id'], 'groupBy' => ['field' => 'status']]));

        $this->assertEquals(['active' => 1, 'inactive' => 1], $min);
        $this->assertEquals(['active' => 3, 'inactive' => 2], $max);
    }

    // ── filters + aggregation (reuses the search WHERE) ──

    public function test_aggregation_respects_filters(): void
    {
        $rows = $this->agg(
            ['metric' => ['fn' => 'count'], 'groupBy' => ['field' => 'category_id']],
            ['where' => ['eq' => ['status' => 'active']]]
        );

        // active: Alice(1), Bob(2), Diana(3)
        $this->assertEquals([1 => 1, 2 => 1, 3 => 1], $this->map($rows));
    }

    // ── ordering + top-N ──

    public function test_order_by_value_desc_with_limit_topN(): void
    {
        $rows = $this->agg([
            'metric' => ['fn' => 'count'],
            'groupBy' => ['field' => 'status'],
            'orderBy' => 'value',
            'direction' => 'desc',
            'limit' => 1,
        ]);

        $this->assertCount(1, $rows);
        $this->assertEquals('active', $rows[0]['group']);
        $this->assertEquals(3, $rows[0]['value']);
    }

    public function test_order_by_group_asc(): void
    {
        $rows = $this->agg([
            'metric' => ['fn' => 'count'],
            'groupBy' => ['field' => 'category_id'],
            'orderBy' => 'group',
            'direction' => 'asc',
        ]);

        $this->assertEquals([1, 2, 3], array_column($rows, 'group'));
    }

    // ── validation (closed set) ──

    public function test_rejects_unknown_function(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->agg(['metric' => ['fn' => 'median']]);
    }

    public function test_rejects_metric_field_not_declared(): void
    {
        $this->expectException(InvalidPayloadException::class);
        // 'name' is not in ->metrics()
        $this->agg(['metric' => ['fn' => 'sum', 'field' => 'name']]);
    }

    public function test_rejects_group_by_non_dimension(): void
    {
        $this->expectException(InvalidPayloadException::class);
        // 'name' is not in ->dimensions()
        $this->agg(['metric' => ['fn' => 'count'], 'groupBy' => ['field' => 'name']]);
    }

    // ── call styles ──

    public function test_builder_aggregate_reads_spec_from_payload(): void
    {
        // whole payload into build(); aggregate() reads the `aggregate` block from it
        $rows = SearchQuery::build(AggTestModel::query(), [
            'where' => ['eq' => ['status' => 'active']],
            'aggregate' => ['metric' => ['fn' => 'count'], 'groupBy' => ['field' => 'category_id']],
        ])->aggregate();

        $this->assertEquals([1 => 1, 2 => 1, 3 => 1], $this->map($rows));
    }
}

class AggTestModel extends Model
{
    use Searchable;

    protected $table = 'test_models';
    protected $guarded = [];

    protected $casts = [
        'category_id' => 'integer',
        'scheduled_at' => 'datetime',
    ];

    public function searchableConfig(): SearchableConfig
    {
        return SearchableConfig::make()
            ->fields(['id', 'name', 'status', 'category_id', 'scheduled_at'])
            ->metrics(['category_id' => ['sum', 'avg', 'min', 'max']])
            ->dimensions(['status', 'category_id'])
            ->sortable(['id', 'name'])
            ->defaultSort('name', 'asc');
    }
}
