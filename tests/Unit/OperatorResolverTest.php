<?php

namespace DartVadius\EloquentSearch\Tests\Unit;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase;
use DartVadius\EloquentSearch\Contracts\CustomFilter;
use DartVadius\EloquentSearch\Searchable;
use DartVadius\EloquentSearch\SearchableConfig;
use DartVadius\EloquentSearch\SearchQuery;
use DartVadius\EloquentSearch\SearchServiceProvider;

/**
 * Tests for OperatorResolver via behavioral verification.
 *
 * Instead of asserting internal operator arrays, we verify that:
 * - auto-resolved operators actually WORK when used in queries
 * - operators NOT in the resolved set are silently SKIPPED
 * - explicit overrides, custom filters, and nullable detection function correctly
 */
class OperatorResolverTest extends TestCase
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
        ResolverTestModel::create([
            'name' => 'Alice', 'status' => 'active', 'category_id' => 1,
            'is_active' => true, 'scheduled_at' => '2026-03-01 10:00:00',
            'tags' => [1, 2, 3],
        ]);
        ResolverTestModel::create([
            'name' => 'Bob', 'status' => 'inactive', 'category_id' => 3,
            'is_active' => false, 'scheduled_at' => '2026-06-15 14:00:00',
            'tags' => [2, 4],
        ]);
        ResolverTestModel::create([
            'name' => 'Charlie', 'status' => 'active', 'category_id' => 5,
            'is_active' => true, 'scheduled_at' => null,
            'tags' => null,
        ]);
    }

    public function test_integer_field_supports_gt_filter(): void
    {
        // category_id is integer — gt should be auto-resolved and work
        $result = SearchQuery::apply(ResolverTestModel::query(), [
            'where' => ['gt' => ['category_id' => 2]],
        ]);

        $this->assertSame(2, $result['total']);
        $names = collect($result['data'])->pluck('name')->sort()->values()->all();
        $this->assertSame(['Bob', 'Charlie'], $names);
    }

    public function test_integer_field_supports_between_filter(): void
    {
        $result = SearchQuery::apply(ResolverTestModel::query(), [
            'where' => ['between' => ['category_id' => [2, 4]]],
        ]);

        $this->assertSame(1, $result['total']);
        $this->assertSame('Bob', $result['data'][0]->name);
    }

    public function test_boolean_field_rejects_like_operator(): void
    {
        // is_active is boolean — 'like' should NOT be in resolved operators → skipped
        $result = SearchQuery::apply(ResolverTestModel::query(), [
            'where' => ['like' => ['is_active' => 'tru']],
        ]);

        // like on boolean is skipped → returns all records
        $this->assertSame(3, $result['total']);
    }

    public function test_boolean_field_supports_eq(): void
    {
        $result = SearchQuery::apply(ResolverTestModel::query(), [
            'where' => ['eq' => ['is_active' => true]],
        ]);

        $this->assertSame(2, $result['total']);
    }

    public function test_datetime_field_supports_between(): void
    {
        // scheduled_at is datetime — between should work
        $result = SearchQuery::apply(ResolverTestModel::query(), [
            'where' => ['between' => ['scheduled_at' => ['2026-01-01', '2026-04-01']]],
        ]);

        $this->assertSame(1, $result['total']);
        $this->assertSame('Alice', $result['data'][0]->name);
    }

    public function test_datetime_field_rejects_like_operator(): void
    {
        // datetime doesn't support 'like' → skipped
        $result = SearchQuery::apply(ResolverTestModel::query(), [
            'where' => ['like' => ['scheduled_at' => '2026']],
        ]);

        $this->assertSame(3, $result['total']);
    }

    public function test_string_field_supports_like(): void
    {
        // name is string — like should work
        $result = SearchQuery::apply(ResolverTestModel::query(), [
            'where' => ['like' => ['name' => 'Ali']],
        ]);

        $this->assertSame(1, $result['total']);
        $this->assertSame('Alice', $result['data'][0]->name);
    }

    public function test_string_field_rejects_gt_operator(): void
    {
        // name is string — gt is NOT in string operators → skipped
        $result = SearchQuery::apply(ResolverTestModel::query(), [
            'where' => ['gt' => ['name' => 'A']],
        ]);

        $this->assertSame(3, $result['total']);
    }

    public function test_nullable_field_supports_is_null(): void
    {
        // scheduled_at is nullable in migration → is_null should be added
        $result = SearchQuery::apply(ResolverTestModel::query(), [
            'where' => ['is_null' => ['scheduled_at' => true]],
        ]);

        $this->assertSame(1, $result['total']);
        $this->assertSame('Charlie', $result['data'][0]->name);
    }

    public function test_non_nullable_field_rejects_is_null(): void
    {
        // status has default and is not nullable → is_null should NOT be resolved
        // But status is string-typed with no explicit nullable() → auto-detect from schema
        $result = SearchQuery::apply(ResolverNonNullModel::query(), [
            'where' => ['is_null' => ['status' => true]],
        ]);

        // is_null skipped for non-nullable field → returns all
        $this->assertSame(3, $result['total']);
    }

    public function test_explicit_operator_override_limits_operators(): void
    {
        // ExplicitModel: status => ['eq', 'in'] — only these should work
        $result = SearchQuery::apply(ResolverExplicitModel::query(), [
            'where' => ['like' => ['status' => 'act']],
        ]);

        // like is not in explicit operators for status → skipped
        $this->assertSame(3, $result['total']);
    }

    public function test_explicit_operator_override_allows_listed_operators(): void
    {
        $result = SearchQuery::apply(ResolverExplicitModel::query(), [
            'where' => ['eq' => ['status' => 'active']],
        ]);

        // eq IS in explicit operators → works
        $this->assertSame(2, $result['total']);
    }

    public function test_custom_filter_operators_override_resolution(): void
    {
        $result = SearchQuery::apply(ResolverCustomFilterModel::query(), [
            'where' => ['between' => ['active_range' => ['2026-01-01', '2026-04-01']]],
        ]);

        // Custom filter maps active_range → scheduled_at between
        $this->assertSame(1, $result['total']);
        $this->assertSame('Alice', $result['data'][0]->name);
    }

    public function test_json_field_supports_json_contains(): void
    {
        $result = SearchQuery::apply(ResolverTestModel::query(), [
            'where' => ['json_contains' => ['tags' => [2]]],
        ]);

        // tags containing 2: Alice [1,2,3] and Bob [2,4]
        $this->assertSame(2, $result['total']);
    }

    public function test_json_field_rejects_eq_operator(): void
    {
        // tags is in jsonFields → only json_contains/json_contains_all resolved
        $result = SearchQuery::apply(ResolverTestModel::query(), [
            'where' => ['eq' => ['tags' => '[1,2,3]']],
        ]);

        // eq not in json operators → skipped
        $this->assertSame(3, $result['total']);
    }

    public function test_unknown_column_falls_back_to_string_operators(): void
    {
        // name is not in $casts and is varchar → should get string operators
        // eq and like should both work
        $result = SearchQuery::apply(ResolverTestModel::query(), [
            'where' => [
                'eq' => ['name' => 'Alice'],
            ],
        ]);

        $this->assertSame(1, $result['total']);
    }
}

class ResolverTestModel extends Model
{
    use Searchable;

    protected $table = 'test_models';
    protected $guarded = [];

    protected $casts = [
        'category_id' => 'integer',
        'is_active' => 'boolean',
        'scheduled_at' => 'datetime',
        'tags' => 'array',
    ];

    public function searchableConfig(): SearchableConfig
    {
        return SearchableConfig::make()
            ->fields(['id', 'name', 'status', 'category_id', 'is_active', 'scheduled_at'])
            ->jsonFields(['tags'])
            ->sortable(['id', 'name'])
            ->defaultSort('name', 'asc');
    }
}

class ResolverNonNullModel extends Model
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

class ResolverExplicitModel extends Model
{
    use Searchable;

    protected $table = 'test_models';
    protected $guarded = [];

    public function searchableConfig(): SearchableConfig
    {
        return SearchableConfig::make()
            ->fields(['id', 'name', 'status' => ['eq', 'in']])
            ->sortable(['id', 'name'])
            ->defaultSort('name', 'asc');
    }
}

class ResolverCustomFilterModel extends Model
{
    use Searchable;

    protected $table = 'test_models';
    protected $guarded = [];

    protected $casts = [
        'scheduled_at' => 'datetime',
    ];

    public function searchableConfig(): SearchableConfig
    {
        return SearchableConfig::make()
            ->fields(['id', 'name', 'status'])
            ->filter('active_range', new ResolverTestDateFilter())
            ->sortable(['id', 'name'])
            ->defaultSort('name', 'asc');
    }
}

class ResolverTestDateFilter implements CustomFilter
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
