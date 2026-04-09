<?php

namespace DartVadius\EloquentSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Orchestra\Testbench\TestCase;
use DartVadius\EloquentSearch\Searchable;
use DartVadius\EloquentSearch\SearchableConfig;
use DartVadius\EloquentSearch\SearchQuery;
use DartVadius\EloquentSearch\SearchServiceProvider;

class SearchOperatorTest extends TestCase
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
        $cat1 = SearchTestCategory::create(['name' => 'Plumbing', 'type' => 'service']);
        $cat2 = SearchTestCategory::create(['name' => 'Electrical', 'type' => 'service']);

        SearchTestTask::create(['name' => 'Fix kitchen pipe', 'status' => 'active', 'category_id' => $cat1->id]);
        SearchTestTask::create(['name' => 'Replace faucet', 'status' => 'active', 'category_id' => $cat1->id]);
        SearchTestTask::create(['name' => 'Wire outlet', 'status' => 'active', 'category_id' => $cat2->id]);
        SearchTestTask::create(['name' => 'General cleanup', 'status' => 'inactive', 'category_id' => null]);
    }

    public function test_search_by_direct_field(): void
    {
        $result = SearchQuery::apply(SearchTestTask::query(), [
            'where' => ['search' => 'pipe'],
        ]);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals('Fix kitchen pipe', $result['data'][0]->name);
    }

    public function test_search_case_insensitive(): void
    {
        $result = SearchQuery::apply(SearchTestTask::query(), [
            'where' => ['search' => 'PIPE'],
        ]);

        // SQLite LIKE is case-insensitive for ASCII
        $this->assertEquals(1, $result['total']);
    }

    public function test_search_by_relation_field(): void
    {
        $result = SearchQuery::apply(SearchTestTask::query(), [
            'where' => ['search' => 'Plumbing'],
        ]);

        // "Plumbing" matches category.name for 2 tasks
        $this->assertEquals(2, $result['total']);
    }

    public function test_search_matches_direct_or_relation(): void
    {
        $result = SearchQuery::apply(SearchTestTask::query(), [
            'where' => ['search' => 'Electr'],
        ]);

        // "Electr" matches category.name "Electrical" → Wire outlet
        $this->assertEquals(1, $result['total']);
        $this->assertEquals('Wire outlet', $result['data'][0]->name);
    }

    public function test_search_no_match(): void
    {
        $result = SearchQuery::apply(SearchTestTask::query(), [
            'where' => ['search' => 'nonexistent'],
        ]);

        $this->assertEquals(0, $result['total']);
    }

    public function test_search_combined_with_other_filters(): void
    {
        $result = SearchQuery::apply(SearchTestTask::query(), [
            'where' => [
                'search' => 'faucet',
                'eq' => ['status' => 'active'],
            ],
        ]);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals('Replace faucet', $result['data'][0]->name);
    }

    public function test_search_escapes_special_chars(): void
    {
        SearchTestTask::create(['name' => '100% done task', 'status' => 'active', 'category_id' => null]);

        $result = SearchQuery::apply(SearchTestTask::query(), [
            'where' => ['search' => '100%'],
        ]);

        $this->assertEquals(1, $result['total']);
        $this->assertStringContainsString('100%', $result['data'][0]->name);
    }

    public function test_search_with_empty_search_fields_does_nothing(): void
    {
        $result = SearchQuery::apply(SearchTestTaskNoSearch::query(), [
            'where' => ['search' => 'pipe'],
        ]);

        // No searchFields configured — $search is a no-op, returns all
        $this->assertEquals(4, $result['total']);
    }

    public function test_search_multiple_relation_fields(): void
    {
        // Search across category.name and category.type
        $result = SearchQuery::apply(SearchTestTask::query(), [
            'where' => ['search' => 'service'],
        ]);

        // "service" matches category.type for Plumbing and Electrical categories → 3 tasks
        $this->assertEquals(3, $result['total']);
    }
}

class SearchTestTask extends Model
{
    use Searchable;

    protected $table = 'test_models';
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(SearchTestCategory::class, 'category_id');
    }

    public function searchableConfig(): SearchableConfig
    {
        return SearchableConfig::make()
            ->fields(['id', 'name', 'status'])
            ->searchFields([
                'name',
                'category.name',
                'category.type',
            ])
            ->sortable(['id', 'name'])
            ->defaultSort('name', 'asc');
    }
}

class SearchTestTaskNoSearch extends Model
{
    use Searchable;

    protected $table = 'test_models';
    protected $guarded = [];

    public function searchableConfig(): SearchableConfig
    {
        return SearchableConfig::make()
            ->fields(['id', 'name', 'status'])
            ->sortable(['id', 'name'])
            ->defaultSort('name', 'asc');
    }
}

class SearchTestCategory extends Model
{
    protected $table = 'test_categories';
    protected $guarded = [];
}
