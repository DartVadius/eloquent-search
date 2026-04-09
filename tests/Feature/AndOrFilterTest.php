<?php

namespace DartVadius\EloquentSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase;
use DartVadius\EloquentSearch\Searchable;
use DartVadius\EloquentSearch\SearchableConfig;
use DartVadius\EloquentSearch\SearchQuery;
use DartVadius\EloquentSearch\SearchServiceProvider;

class AndOrFilterTest extends TestCase
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
        AndOrTestModel::create(['name' => 'Alice', 'status' => 'active', 'category_id' => 1, 'is_active' => true, 'scheduled_at' => '2026-04-01 10:00:00']);
        AndOrTestModel::create(['name' => 'Bob', 'status' => 'active', 'category_id' => 2, 'is_active' => true, 'scheduled_at' => '2026-04-05 14:00:00']);
        AndOrTestModel::create(['name' => 'Charlie', 'status' => 'inactive', 'category_id' => 1, 'is_active' => false, 'scheduled_at' => '2026-04-10 08:00:00']);
        AndOrTestModel::create(['name' => 'Diana', 'status' => 'active', 'category_id' => 3, 'is_active' => true, 'scheduled_at' => null]);
        AndOrTestModel::create(['name' => 'Eve', 'status' => 'inactive', 'category_id' => 2, 'is_active' => false, 'scheduled_at' => '2026-04-15 16:00:00']);
    }

    public function test_and_or_with_multiple_operators_in_one_group(): void
    {
        $result = SearchQuery::apply(AndOrTestModel::query(), [
            'where' => [
                'and_or' => [
                    ['eq' => ['status' => 'active'], 'gte' => ['category_id' => 3]],
                ],
            ],
        ]);

        // Group: (status = 'active') OR (category_id >= 3)
        // status=active: Alice(1), Bob(2), Diana(3) = 3
        // category_id >= 3: Diana(3)
        // Union: Alice, Bob, Diana = 3
        $this->assertEquals(3, $result['total']);
    }

    public function test_and_or_with_multiple_groups_anded_together(): void
    {
        $result = SearchQuery::apply(AndOrTestModel::query(), [
            'where' => [
                'and_or' => [
                    ['eq' => ['status' => 'active']],
                    ['gte' => ['category_id' => 2]],
                ],
            ],
        ]);

        // Group 1: status = 'active' => Alice, Bob, Diana
        // Group 2: category_id >= 2 => Bob, Diana, Eve
        // AND: Bob, Diana
        $this->assertEquals(2, $result['total']);
        $names = collect($result['data'])->pluck('name')->sort()->values()->all();
        $this->assertEquals(['Bob', 'Diana'], $names);
    }

    public function test_and_or_combined_with_regular_where_conditions(): void
    {
        $result = SearchQuery::apply(AndOrTestModel::query(), [
            'where' => [
                'eq' => ['is_active' => true],
                'and_or' => [
                    ['eq' => ['status' => 'active']],
                    ['gte' => ['category_id' => 2]],
                ],
            ],
        ]);

        // is_active = true: Alice, Bob, Diana
        // AND group 1 (status=active): Alice, Bob, Diana => Alice, Bob, Diana
        // AND group 2 (category_id >= 2): Bob, Diana, Eve => Bob, Diana
        $this->assertEquals(2, $result['total']);
        $names = collect($result['data'])->pluck('name')->sort()->values()->all();
        $this->assertEquals(['Bob', 'Diana'], $names);
    }

    public function test_and_or_with_no_matching_results(): void
    {
        $result = SearchQuery::apply(AndOrTestModel::query(), [
            'where' => [
                'and_or' => [
                    ['eq' => ['status' => 'nonexistent']],
                ],
            ],
        ]);

        $this->assertEquals(0, $result['total']);
        $this->assertCount(0, $result['data']);
    }
}

class AndOrTestModel extends Model
{
    use Searchable;

    protected $table = 'test_models';
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'scheduled_at' => 'datetime',
        'tags' => 'array',
    ];

    public function searchableConfig(): SearchableConfig
    {
        return SearchableConfig::make()
            ->fields([
                'id', 'name', 'status', 'category_id',
                'is_active', 'scheduled_at',
            ])
            ->jsonFields(['tags'])
            ->sortable(['id', 'name', 'scheduled_at'])
            ->defaultSort('name', 'asc');
    }
}
