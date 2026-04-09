<?php

namespace Shifton\EloquentSearch\Tests\Unit;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase;
use Shifton\EloquentSearch\Searchable;
use Shifton\EloquentSearch\SearchableConfig;
use Shifton\EloquentSearch\SearchBuilder;
use Shifton\EloquentSearch\SearchQuery;
use Shifton\EloquentSearch\SearchServiceProvider;

class SearchBuilderTest extends TestCase
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
        BuilderTestModel::create(['name' => 'Alice', 'status' => 'active', 'category_id' => 1, 'is_active' => true]);
        BuilderTestModel::create(['name' => 'Bob', 'status' => 'inactive', 'category_id' => 2, 'is_active' => false]);
        BuilderTestModel::create(['name' => 'Charlie', 'status' => 'active', 'category_id' => 1, 'is_active' => true]);
    }

    public function test_get_returns_collection(): void
    {
        $builder = SearchQuery::build(BuilderTestModel::query(), [
            'where' => ['eq' => ['status' => 'active']],
        ]);

        $result = $builder->get();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
    }

    public function test_get_query_returns_eloquent_builder(): void
    {
        $builder = SearchQuery::build(BuilderTestModel::query(), []);

        $query = $builder->getQuery();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $query);
    }

    public function test_get_query_allows_further_constraint_chaining(): void
    {
        $builder = SearchQuery::build(BuilderTestModel::query(), [
            'where' => ['eq' => ['status' => 'active']],
        ]);

        $query = $builder->getQuery();
        $query->where('name', 'Alice');

        $results = $query->get();
        $this->assertCount(1, $results);
        $this->assertSame('Alice', $results->first()->name);
    }

    public function test_count_returns_integer(): void
    {
        $builder = SearchQuery::build(BuilderTestModel::query(), [
            'where' => ['eq' => ['status' => 'active']],
        ]);

        $this->assertSame(2, $builder->count());
    }

    public function test_paginate_returns_array_with_pagination_keys(): void
    {
        $builder = SearchQuery::build(BuilderTestModel::query(), []);

        $result = $builder->paginate();

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('per_page', $result);
        $this->assertArrayHasKey('last_page', $result);
        $this->assertSame(3, $result['total']);
    }
}

class BuilderTestModel extends Model
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
