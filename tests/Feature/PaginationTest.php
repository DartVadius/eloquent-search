<?php

namespace Shifton\EloquentSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase;
use Shifton\EloquentSearch\Pagination\SearchPaginator;
use Shifton\EloquentSearch\Searchable;
use Shifton\EloquentSearch\SearchableConfig;
use Shifton\EloquentSearch\SearchQuery;
use Shifton\EloquentSearch\SearchServiceProvider;

class PaginationTest extends TestCase
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
        for ($i = 1; $i <= 5; $i++) {
            PaginationTestModel::create([
                'name' => 'Item ' . str_pad($i, 2, '0', STR_PAD_LEFT),
                'status' => $i % 2 === 0 ? 'inactive' : 'active',
                'category_id' => $i,
                'is_active' => true,
            ]);
        }
    }

    public function test_per_page_capped_at_max(): void
    {
        $this->app['config']->set('eloquent-search.pagination.max_per_page', 1000);

        $result = SearchQuery::apply(PaginationTestModel::query(), [
            'per_page' => 5000,
        ]);

        $this->assertSame(1000, $result['per_page']);
    }

    public function test_default_per_page_used_when_not_specified(): void
    {
        $this->app['config']->set('eloquent-search.pagination.default_per_page', 25);

        $result = SearchQuery::apply(PaginationTestModel::query(), []);

        $this->assertSame(25, $result['per_page']);
    }

    public function test_page_beyond_data_returns_empty(): void
    {
        $result = SearchQuery::apply(PaginationTestModel::query(), [
            'page' => 100,
            'per_page' => 25,
        ]);

        $this->assertCount(0, $result['data']);
        $this->assertSame(5, $result['total']);
        $this->assertSame(100, $result['page']);
        $this->assertSame(1, $result['last_page']);
    }

    public function test_count_only_skips_data(): void
    {
        $result = SearchQuery::apply(PaginationTestModel::query(), [
            'count_only' => true,
        ]);

        $this->assertSame(5, $result['total']);
        $this->assertArrayNotHasKey('data', $result);
        $this->assertArrayNotHasKey('page', $result);
        $this->assertArrayNotHasKey('per_page', $result);
        $this->assertArrayNotHasKey('last_page', $result);
    }

    public function test_custom_config_values_respected(): void
    {
        $this->app['config']->set('eloquent-search.pagination.default_per_page', 10);
        $this->app['config']->set('eloquent-search.pagination.max_per_page', 50);

        $result = SearchQuery::apply(PaginationTestModel::query(), [
            'per_page' => 100,
        ]);

        $this->assertSame(50, $result['per_page']);
    }

    public function test_second_page_of_results(): void
    {
        $result = SearchQuery::apply(PaginationTestModel::query(), [
            'page' => 2,
            'per_page' => 2,
        ]);

        $this->assertCount(2, $result['data']);
        $this->assertSame(2, $result['page']);
        $this->assertSame(5, $result['total']);

        // Verify these are different items from page 1
        $page1 = SearchQuery::apply(PaginationTestModel::query(), [
            'page' => 1,
            'per_page' => 2,
        ]);

        $page1Ids = array_map(fn ($item) => $item->id, $page1['data']);
        $page2Ids = array_map(fn ($item) => $item->id, $result['data']);
        $this->assertEmpty(array_intersect($page1Ids, $page2Ids));
    }

    public function test_last_page_with_fewer_items(): void
    {
        $result = SearchQuery::apply(PaginationTestModel::query(), [
            'page' => 3,
            'per_page' => 2,
        ]);

        $this->assertCount(1, $result['data']);
        $this->assertSame(3, $result['last_page']);
        $this->assertSame(5, $result['total']);
    }

    public function test_per_page_exceeding_max_is_capped(): void
    {
        $this->app['config']->set('eloquent-search.pagination.max_per_page', 3);

        $result = SearchQuery::apply(PaginationTestModel::query(), [
            'per_page' => 2000,
        ]);

        $this->assertSame(3, $result['per_page']);
        $this->assertCount(3, $result['data']);
    }
}

class PaginationTestModel extends Model
{
    use Searchable;

    protected $table = 'test_models';
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function searchableConfig(): SearchableConfig
    {
        return SearchableConfig::make()
            ->fields(['id', 'name', 'status', 'category_id', 'is_active'])
            ->sortable(['id', 'name'])
            ->defaultSort('id', 'asc');
    }
}
