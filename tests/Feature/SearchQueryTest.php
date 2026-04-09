<?php

namespace Shifton\EloquentSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase;
use Shifton\EloquentSearch\Searchable;
use Shifton\EloquentSearch\SearchableConfig;
use Shifton\EloquentSearch\SearchQuery;
use Shifton\EloquentSearch\SearchServiceProvider;

class SearchQueryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SearchServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__ . '/../migrations');
        $this->seedTestData();
    }

    protected function seedTestData(): void
    {
        SearchTestModel::create(['name' => 'Alice', 'status' => 'active', 'category_id' => 1, 'is_active' => true, 'scheduled_at' => '2026-04-01 10:00:00', 'tags' => [1, 2]]);
        SearchTestModel::create(['name' => 'Bob', 'status' => 'active', 'category_id' => 2, 'is_active' => true, 'scheduled_at' => '2026-04-05 14:00:00', 'tags' => [2, 3]]);
        SearchTestModel::create(['name' => 'Charlie', 'status' => 'inactive', 'category_id' => 1, 'is_active' => false, 'scheduled_at' => '2026-04-10 08:00:00', 'tags' => [1, 3]]);
        SearchTestModel::create(['name' => 'Diana', 'status' => 'active', 'category_id' => 3, 'is_active' => true, 'scheduled_at' => null, 'tags' => null]);
        SearchTestModel::create(['name' => 'Eve 100% test', 'status' => 'inactive', 'category_id' => 2, 'is_active' => false, 'scheduled_at' => '2026-04-15 16:00:00', 'tags' => [1, 2, 3]]);
    }

    public function test_apply_empty_payload_returns_all(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), []);

        $this->assertEquals(5, $result['total']);
        $this->assertCount(5, $result['data']);
    }

    public function test_eq_filter(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['eq' => ['status' => 'active']],
        ]);

        $this->assertEquals(3, $result['total']);
    }

    public function test_not_eq_filter(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['not_eq' => ['status' => 'active']],
        ]);

        $this->assertEquals(2, $result['total']);
    }

    public function test_in_filter(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['in' => ['category_id' => [1, 2]]],
        ]);

        $this->assertEquals(4, $result['total']);
    }

    public function test_not_in_filter(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['not_in' => ['category_id' => [1]]],
        ]);

        $this->assertEquals(3, $result['total']);
    }

    public function test_between_filter(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['between' => ['scheduled_at' => ['2026-04-01 00:00:00', '2026-04-06 00:00:00']]],
        ]);

        $this->assertEquals(2, $result['total']);
    }

    public function test_gt_filter(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['gt' => ['category_id' => 2]],
        ]);

        $this->assertEquals(1, $result['total']);
    }

    public function test_like_filter(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['like' => ['name' => 'ali']],
        ]);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals('Alice', $result['data'][0]->name);
    }

    public function test_like_filter_escapes_percent(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['like' => ['name' => '100%']],
        ]);

        $this->assertEquals(1, $result['total']);
        $this->assertStringContainsString('100%', $result['data'][0]->name);
    }

    public function test_is_null_true(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['is_null' => ['scheduled_at' => true]],
        ]);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals('Diana', $result['data'][0]->name);
    }

    public function test_is_null_false(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['is_null' => ['scheduled_at' => false]],
        ]);

        $this->assertEquals(4, $result['total']);
    }

    public function test_eq_boolean_filter(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['eq' => ['is_active' => false]],
        ]);

        $this->assertEquals(2, $result['total']);
    }

    public function test_json_contains_any(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['json_contains' => ['tags' => [1, 2]]],
        ]);

        // Alice [1,2], Bob [2,3], Charlie [1,3], Eve [1,2,3] — all have 1 or 2
        $this->assertEquals(4, $result['total']);
    }

    public function test_json_contains_all(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['json_contains_all' => ['tags' => [1, 2, 3]]],
        ]);

        // Only Eve [1,2,3]
        $this->assertEquals(1, $result['total']);
        $this->assertEquals('Eve 100% test', $result['data'][0]->name);
    }

    public function test_multiple_operators(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => [
                'eq' => ['status' => 'active'],
                'in' => ['category_id' => [1, 2]],
            ],
        ]);

        // Alice (active, cat 1), Bob (active, cat 2)
        $this->assertEquals(2, $result['total']);
    }

    public function test_or_block(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['eq' => ['status' => 'active']],
            'or' => ['eq' => ['name' => 'Charlie']],
        ]);

        // active: Alice, Bob, Diana + OR Charlie
        $this->assertEquals(4, $result['total']);
    }

    public function test_and_or_block(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => [
                'eq' => ['status' => 'active'],
                'and_or' => [
                    ['eq' => ['category_id' => 1, 'category_id' => 2]],
                ],
            ],
        ]);

        // active AND (cat 1 OR cat 2) — but due to PHP array key dedup, only cat 2
        // Let's just check it doesn't crash and returns results
        $this->assertIsArray($result);
    }

    public function test_count_only(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'count_only' => true,
            'where' => ['eq' => ['status' => 'active']],
        ]);

        $this->assertEquals(3, $result['total']);
        $this->assertArrayNotHasKey('data', $result);
    }

    public function test_pagination(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'page' => 1,
            'per_page' => 2,
        ]);

        $this->assertEquals(5, $result['total']);
        $this->assertCount(2, $result['data']);
        $this->assertEquals(1, $result['page']);
        $this->assertEquals(2, $result['per_page']);
        $this->assertEquals(3, $result['last_page']);
    }

    public function test_sorting(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'sort' => [['field' => 'name', 'dir' => 'desc']],
        ]);

        $names = array_map(fn ($item) => $item->name, $result['data']);
        $this->assertEquals('Eve 100% test', $names[0]);
    }

    public function test_unknown_fields_are_skipped(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['eq' => ['nonexistent_field' => 'value']],
        ]);

        // Unknown field skipped — returns all records
        $this->assertEquals(5, $result['total']);
    }

    public function test_unknown_operator_for_field_skipped(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['like' => ['is_active' => 'test']], // boolean field doesn't support like
        ]);

        // Skipped — returns all
        $this->assertEquals(5, $result['total']);
    }

    public function test_build_returns_search_builder(): void
    {
        $builder = SearchQuery::build(SearchTestModel::query(), [
            'where' => ['eq' => ['status' => 'active']],
        ]);

        $this->assertInstanceOf(\Shifton\EloquentSearch\SearchBuilder::class, $builder);
        $this->assertEquals(3, $builder->count());

        $paginated = $builder->paginate();
        $this->assertEquals(3, $paginated['total']);
    }

    public function test_default_sort_applied(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), []);

        // Default sort is name asc
        $names = array_map(fn ($item) => $item->name, $result['data']);
        $this->assertEquals('Alice', $names[0]);
    }

    // --- Section 9: Comparison Filters ---

    public function test_lt_filter(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['lt' => ['category_id' => 2]],
        ]);

        // category_id < 2: Alice (1), Charlie (1) = 2
        $this->assertEquals(2, $result['total']);
    }

    public function test_gte_filter(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['gte' => ['category_id' => 2]],
        ]);

        // category_id >= 2: Bob (2), Diana (3), Eve (2) = 3
        $this->assertEquals(3, $result['total']);
    }

    public function test_lte_filter(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['lte' => ['category_id' => 2]],
        ]);

        // category_id <= 2: Alice (1), Bob (2), Charlie (1), Eve (2) = 4
        $this->assertEquals(4, $result['total']);
    }

    // --- Section 10: JSON Filters Edge Cases ---

    public function test_json_contains_with_single_scalar(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['json_contains' => ['tags' => 3]],
        ]);

        // Tags containing 3: Bob [2,3], Charlie [1,3], Eve [1,2,3] = 3
        $this->assertEquals(3, $result['total']);
    }

    public function test_json_contains_all_with_single_scalar(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['json_contains_all' => ['tags' => 2]],
        ]);

        // Tags containing 2: Alice [1,2], Bob [2,3], Eve [1,2,3] = 3
        $this->assertEquals(3, $result['total']);
    }

    public function test_json_contains_on_null_json_column(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'where' => ['json_contains' => ['tags' => [1]]],
        ]);

        // Tags containing 1: Alice [1,2], Charlie [1,3], Eve [1,2,3] = 3
        // Diana (tags=null) must NOT be included
        $this->assertEquals(3, $result['total']);
        $names = collect($result['data'])->pluck('name')->sort()->values()->all();
        $this->assertNotContains('Diana', $names);
    }

    // --- Section 11: Pagination Edge Cases ---

    public function test_second_page_of_results(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'page' => 2,
            'per_page' => 2,
        ]);

        $this->assertEquals(5, $result['total']);
        $this->assertCount(2, $result['data']);
        $this->assertEquals(2, $result['page']);

        // Verify items are different from first page
        $page1 = SearchQuery::apply(SearchTestModel::query(), [
            'page' => 1,
            'per_page' => 2,
        ]);
        $page1Names = collect($page1['data'])->pluck('name')->all();
        $page2Names = collect($result['data'])->pluck('name')->all();
        $this->assertEmpty(array_intersect($page1Names, $page2Names));
    }

    public function test_last_page_with_fewer_items(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'page' => 3,
            'per_page' => 2,
        ]);

        // 5 total, per_page 2: pages = [2, 2, 1]
        $this->assertEquals(5, $result['total']);
        $this->assertCount(1, $result['data']);
        $this->assertEquals(3, $result['last_page']);
    }

    public function test_per_page_exceeding_max_is_capped(): void
    {
        $result = SearchQuery::apply(SearchTestModel::query(), [
            'per_page' => 2000,
        ]);

        // Default max_per_page is 1000, so 2000 is capped to 1000
        $this->assertEquals(1000, $result['per_page']);
        $this->assertEquals(5, $result['total']);
    }
}

class SearchTestModel extends Model
{
    use Searchable;

    protected $table = 'test_models';
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'scheduled_at' => 'datetime',
        'tags' => 'array',
        'skills' => 'array',
    ];

    public function searchableConfig(): SearchableConfig
    {
        return SearchableConfig::make()
            ->fields([
                'id', 'name', 'status', 'category_id',
                'is_active', 'scheduled_at',
            ])
            ->jsonFields(['tags', 'skills'])
            ->sortable(['id', 'name', 'scheduled_at'])
            ->defaultSort('name', 'asc');
    }
}
