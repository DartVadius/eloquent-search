<?php

namespace Shifton\EloquentSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase;
use Shifton\EloquentSearch\Exceptions\InvalidPayloadException;
use Shifton\EloquentSearch\Searchable;
use Shifton\EloquentSearch\SearchableConfig;
use Shifton\EloquentSearch\SearchQuery;
use Shifton\EloquentSearch\SearchServiceProvider;

class SearchQueryErrorTest extends TestCase
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
        ErrorTestSearchModel::create(['name' => 'Alice', 'status' => 'active', 'category_id' => 1, 'is_active' => true]);
        ErrorTestSearchModel::create(['name' => 'Bob', 'status' => 'inactive', 'category_id' => 2, 'is_active' => false]);
    }

    public function test_model_without_searchable_trait_throws_runtime_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must use the Searchable trait');

        SearchQuery::apply(NonSearchableModel::query(), []);
    }

    public function test_on_unknown_field_throw_throws_for_unknown_field(): void
    {
        $this->app['config']->set('eloquent-search.on_unknown_field', 'throw');

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('Unknown field');

        SearchQuery::apply(ErrorTestSearchModel::query(), [
            'where' => ['eq' => ['nonexistent_field' => 'value']],
        ]);
    }

    public function test_on_unknown_field_skip_silently_ignores_unknown_field(): void
    {
        $this->app['config']->set('eloquent-search.on_unknown_field', 'skip');

        $result = SearchQuery::apply(ErrorTestSearchModel::query(), [
            'where' => ['eq' => ['nonexistent_field' => 'value']],
        ]);

        // Unknown field skipped — returns all records
        $this->assertSame(2, $result['total']);
    }
}

class NonSearchableModel extends Model
{
    protected $table = 'test_models';
    protected $guarded = [];
}

class ErrorTestSearchModel extends Model
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
            ->defaultSort('name', 'asc');
    }
}
