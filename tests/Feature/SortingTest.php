<?php

namespace Shifton\EloquentSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase;
use Shifton\EloquentSearch\Searchable;
use Shifton\EloquentSearch\SearchableConfig;
use Shifton\EloquentSearch\SearchQuery;
use Shifton\EloquentSearch\SearchServiceProvider;
use Shifton\EloquentSearch\Sorting\SortApplier;

class SortingTest extends TestCase
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
        SortTestModel::create(['name' => 'Charlie', 'status' => 'active', 'category_id' => 1, 'is_active' => true]);
        SortTestModel::create(['name' => 'Alice', 'status' => 'inactive', 'category_id' => 2, 'is_active' => false]);
        SortTestModel::create(['name' => 'Bob', 'status' => 'active', 'category_id' => 1, 'is_active' => true]);
        SortTestModel::create(['name' => 'Diana', 'status' => 'inactive', 'category_id' => 3, 'is_active' => false]);
        SortTestModel::create(['name' => 'Eve', 'status' => 'active', 'category_id' => 2, 'is_active' => true]);
    }

    public function test_sort_ascending(): void
    {
        $result = SearchQuery::apply(SortTestModel::query(), [
            'sort' => [['field' => 'name', 'dir' => 'asc']],
        ]);

        $names = array_map(fn ($item) => $item->name, $result['data']);
        $this->assertSame('Alice', $names[0]);
        $this->assertSame('Bob', $names[1]);
        $this->assertSame('Charlie', $names[2]);
    }

    public function test_sort_by_multiple_fields(): void
    {
        $result = SearchQuery::apply(SortTestModel::query(), [
            'sort' => [
                ['field' => 'status', 'dir' => 'asc'],
                ['field' => 'name', 'dir' => 'desc'],
            ],
        ]);

        $names = array_map(fn ($item) => $item->name, $result['data']);
        // active first (asc), then within active: Eve, Charlie, Bob (desc)
        $this->assertSame('Eve', $names[0]);
        $this->assertSame('Charlie', $names[1]);
        $this->assertSame('Bob', $names[2]);
        // inactive next: Diana, Alice (desc)
        $this->assertSame('Diana', $names[3]);
        $this->assertSame('Alice', $names[4]);
    }

    public function test_sort_by_non_sortable_field_falls_back_to_default(): void
    {
        $result = SearchQuery::apply(SortTestModel::query(), [
            'sort' => [['field' => 'category_id']],
        ]);

        // category_id is not sortable, so default sort (name asc) applied
        $names = array_map(fn ($item) => $item->name, $result['data']);
        $this->assertSame('Alice', $names[0]);
    }

    public function test_sort_dir_defaults_to_asc_when_omitted(): void
    {
        $result = SearchQuery::apply(SortTestModel::query(), [
            'sort' => [['field' => 'name']],
        ]);

        $names = array_map(fn ($item) => $item->name, $result['data']);
        $this->assertSame('Alice', $names[0]);
        $this->assertSame('Eve', $names[4]);
    }

    public function test_default_sort_not_applied_when_explicit_sort_succeeds(): void
    {
        $result = SearchQuery::apply(SortTestModel::query(), [
            'sort' => [['field' => 'id', 'dir' => 'desc']],
        ]);

        $names = array_map(fn ($item) => $item->name, $result['data']);
        // Inserted order: Charlie(1), Alice(2), Bob(3), Diana(4), Eve(5) => desc: Eve, Diana, Bob, Alice, Charlie
        $this->assertSame('Eve', $names[0]);
        $this->assertSame('Charlie', $names[4]);
    }

    public function test_no_default_sort_when_not_configured(): void
    {
        $result = SearchQuery::apply(SortNoDefaultModel::query(), []);

        // No default sort and no explicit sort — order is undefined (insertion order for SQLite)
        $this->assertSame(5, $result['total']);
    }

    public function test_mixed_valid_invalid_sort_fields(): void
    {
        $result = SearchQuery::apply(SortTestModel::query(), [
            'sort' => [
                ['field' => 'unknown_field', 'dir' => 'asc'],
                ['field' => 'name', 'dir' => 'desc'],
            ],
        ]);

        // unknown_field skipped, name desc applied, default NOT applied
        $names = array_map(fn ($item) => $item->name, $result['data']);
        $this->assertSame('Eve', $names[0]);
        $this->assertSame('Alice', $names[4]);
    }

    public function test_all_sort_fields_invalid_falls_back_to_default(): void
    {
        $result = SearchQuery::apply(SortTestModel::query(), [
            'sort' => [
                ['field' => 'unknown1'],
                ['field' => 'unknown2'],
            ],
        ]);

        // All invalid — default sort (name asc) applied
        $names = array_map(fn ($item) => $item->name, $result['data']);
        $this->assertSame('Alice', $names[0]);
    }
}

class SortTestModel extends Model
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
            ->sortable(['id', 'name', 'status'])
            ->defaultSort('name', 'asc');
    }
}

class SortNoDefaultModel extends Model
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
            ->fields(['id', 'name', 'status'])
            ->sortable(['id', 'name']);
    }
}
