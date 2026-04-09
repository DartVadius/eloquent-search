<?php

namespace DartVadius\EloquentSearch\Tests\Unit;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase;
use DartVadius\EloquentSearch\Filters\BetweenFilter;
use DartVadius\EloquentSearch\Filters\ComparisonFilter;
use DartVadius\EloquentSearch\Filters\EqFilter;
use DartVadius\EloquentSearch\Filters\InFilter;
use DartVadius\EloquentSearch\Filters\IsNullFilter;
use DartVadius\EloquentSearch\Filters\LikeFilter;
use DartVadius\EloquentSearch\Filters\NotEqFilter;
use DartVadius\EloquentSearch\Filters\NotInFilter;
use DartVadius\EloquentSearch\SearchServiceProvider;

class FiltersTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SearchServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__ . '/../migrations');
    }

    protected function getBuilder(): Builder
    {
        return TestModel::query();
    }

    public function test_eq_filter(): void
    {
        $builder = $this->getBuilder();
        (new EqFilter())->apply($builder, 'name', 'John');

        $sql = $builder->toSql();
        $this->assertStringContainsString('"name" = ?', $sql);
    }

    public function test_not_eq_filter(): void
    {
        $builder = $this->getBuilder();
        (new NotEqFilter())->apply($builder, 'name', 'John');

        $sql = $builder->toSql();
        $this->assertStringContainsString('"name" != ?', $sql);
    }

    public function test_in_filter(): void
    {
        $builder = $this->getBuilder();
        (new InFilter())->apply($builder, 'id', [1, 2, 3]);

        $sql = $builder->toSql();
        $this->assertStringContainsString('in', strtolower($sql));
    }

    public function test_not_in_filter(): void
    {
        $builder = $this->getBuilder();
        (new NotInFilter())->apply($builder, 'id', [1, 2, 3]);

        $sql = $builder->toSql();
        $this->assertStringContainsString('not in', strtolower($sql));
    }

    public function test_between_filter(): void
    {
        $builder = $this->getBuilder();
        (new BetweenFilter())->apply($builder, 'id', [1, 100]);

        $sql = $builder->toSql();
        $this->assertStringContainsString('between', strtolower($sql));
    }

    public function test_comparison_gt_filter(): void
    {
        $builder = $this->getBuilder();
        (new ComparisonFilter('>'))->apply($builder, 'id', 5);

        $sql = $builder->toSql();
        $this->assertStringContainsString('> ?', $sql);
    }

    public function test_comparison_lte_filter(): void
    {
        $builder = $this->getBuilder();
        (new ComparisonFilter('<='))->apply($builder, 'id', 100);

        $sql = $builder->toSql();
        $this->assertStringContainsString('<= ?', $sql);
    }

    public function test_like_filter_escapes_special_chars(): void
    {
        $builder = $this->getBuilder();
        (new LikeFilter())->apply($builder, 'name', '100%_test');

        $bindings = $builder->getBindings();
        // The LIKE value should have % and _ escaped with backslash
        $this->assertStringContainsString('100', $bindings[0]);
        $this->assertStringStartsWith('%', $bindings[0]);
        $this->assertStringEndsWith('%', $bindings[0]);
        // Verify special chars are escaped (backslash before % and _)
        $this->assertStringNotContainsString('100%_', $bindings[0]);
    }

    public function test_is_null_true(): void
    {
        $builder = $this->getBuilder();
        (new IsNullFilter())->apply($builder, 'name', true);

        $sql = $builder->toSql();
        $this->assertStringContainsString('is null', strtolower($sql));
    }

    public function test_is_null_false(): void
    {
        $builder = $this->getBuilder();
        (new IsNullFilter())->apply($builder, 'name', false);

        $sql = $builder->toSql();
        $this->assertStringContainsString('is not null', strtolower($sql));
    }
}

class TestModel extends Model
{
    protected $table = 'test_models';
}
