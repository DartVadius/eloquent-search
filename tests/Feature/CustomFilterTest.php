<?php

namespace DartVadius\EloquentSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase;
use DartVadius\EloquentSearch\Contracts\CustomFilter;
use DartVadius\EloquentSearch\Searchable;
use DartVadius\EloquentSearch\SearchableConfig;
use DartVadius\EloquentSearch\SearchQuery;
use DartVadius\EloquentSearch\SearchServiceProvider;

class CustomFilterTest extends TestCase
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
        CustomFilterTestModel::create([
            'name' => 'Alice',
            'status' => 'active',
            'category_id' => 1,
            'is_active' => true,
            'scheduled_at' => '2026-01-15 10:00:00',
        ]);
        CustomFilterTestModel::create([
            'name' => 'Bob',
            'status' => 'active',
            'category_id' => 2,
            'is_active' => true,
            'scheduled_at' => '2026-06-20 14:00:00',
        ]);
        CustomFilterTestModel::create([
            'name' => 'Charlie',
            'status' => 'inactive',
            'category_id' => 1,
            'is_active' => false,
            'scheduled_at' => '2027-03-10 08:00:00',
        ]);
        CustomFilterTestModel::create([
            'name' => 'Diana',
            'status' => 'active',
            'category_id' => 3,
            'is_active' => true,
            'scheduled_at' => null,
        ]);
    }

    public function test_custom_filter_applied_for_whitelisted_operator(): void
    {
        $result = SearchQuery::apply(CustomFilterTestModel::query(), [
            'where' => [
                'between' => ['date_range' => ['2026-01-01 00:00:00', '2026-12-31 23:59:59']],
            ],
        ]);

        // Alice (2026-01-15) and Bob (2026-06-20) are within range
        $this->assertEquals(2, $result['total']);
        $names = collect($result['data'])->pluck('name')->sort()->values()->all();
        $this->assertEquals(['Alice', 'Bob'], $names);
    }

    public function test_custom_filter_ignores_non_whitelisted_operator(): void
    {
        $result = SearchQuery::apply(CustomFilterTestModel::query(), [
            'where' => [
                'like' => ['date_range' => 'test'],
            ],
        ]);

        // 'like' is not in allowedOperators() for date_range custom filter, so skipped
        $this->assertEquals(4, $result['total']);
    }

    public function test_custom_filter_in_and_or_block(): void
    {
        $result = SearchQuery::apply(CustomFilterTestModel::query(), [
            'where' => [
                'and_or' => [
                    [
                        'eq' => ['status' => 'inactive'],
                        'between' => ['date_range' => ['2026-01-01 00:00:00', '2026-03-01 00:00:00']],
                    ],
                ],
            ],
        ]);

        // Group: (status = 'inactive') OR (date_range between 2026-01-01 and 2026-03-01)
        // inactive: Charlie
        // date_range match: Alice (2026-01-15)
        // Combined: Charlie + Alice = 2
        $this->assertEquals(2, $result['total']);
    }

    public function test_custom_filter_field_not_in_fields_still_works(): void
    {
        $result = SearchQuery::apply(CustomFilterVirtualModel::query(), [
            'where' => [
                'eq' => ['status_alias' => 'active'],
            ],
        ]);

        // status_alias is a custom filter that maps to status column
        // 'active' status: Alice, Bob, Diana = 3
        $this->assertEquals(3, $result['total']);
    }
}

class TestDateRangeFilter implements CustomFilter
{
    public function apply(Builder $query, string $operator, mixed $value): void
    {
        if ($operator === 'between' && is_array($value) && count($value) === 2) {
            $query->whereBetween('scheduled_at', $value);
        }
    }

    public function allowedOperators(): array
    {
        return ['between'];
    }
}

class TestStatusAliasFilter implements CustomFilter
{
    public function apply(Builder $query, string $operator, mixed $value): void
    {
        if ($operator === 'eq') {
            $query->where('status', '=', $value);
        }
    }

    public function allowedOperators(): array
    {
        return ['eq'];
    }
}

class CustomFilterTestModel extends Model
{
    use Searchable;

    protected $table = 'test_models';
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'scheduled_at' => 'datetime',
    ];

    public function searchableConfig(): SearchableConfig
    {
        return SearchableConfig::make()
            ->fields(['id', 'name', 'status', 'category_id', 'is_active', 'scheduled_at'])
            ->filter('date_range', new TestDateRangeFilter())
            ->sortable(['id', 'name'])
            ->defaultSort('name', 'asc');
    }
}

class CustomFilterVirtualModel extends Model
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
            ->fields(['id', 'name', 'category_id'])
            ->filter('status_alias', new TestStatusAliasFilter())
            ->sortable(['id', 'name'])
            ->defaultSort('name', 'asc');
    }
}
