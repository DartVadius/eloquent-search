<?php

namespace DartVadius\EloquentSearch;

use Illuminate\Database\Eloquent\Builder;
use DartVadius\EloquentSearch\Parser\OperatorResolver;
use DartVadius\EloquentSearch\Parser\PayloadValidator;
use DartVadius\EloquentSearch\Parser\QueryParser;
use DartVadius\EloquentSearch\Sorting\SortApplier;

class SearchQuery
{
    /**
     * Apply filters, sorting, and pagination — return paginated result.
     *
     * @param array<string> $allowedRelations Relations allowed for $has filtering (role-based whitelist)
     */
    public static function apply(Builder $query, array $payload, array $allowedRelations = [], ?SearchableConfig $config = null): array
    {
        $builder = static::build($query, $payload, $allowedRelations, $config);

        return $builder->paginate();
    }

    /**
     * Apply filters and run an aggregation from a single payload — return the aggregate rows.
     * The `aggregate` block in the payload defines the metric / group-by; `where`/`or`/`has`
     * filter the data first. Mirror of apply(), but the terminal is GROUP BY instead of pagination.
     *
     * @param array<string> $allowedRelations Relations allowed for $has filtering (role-based whitelist)
     * @return array<array{group?: mixed, value?: int|float, values?: array<string,int|float>}>
     */
    public static function aggregate(Builder $query, array $payload, array $allowedRelations = [], ?SearchableConfig $config = null): array
    {
        return static::build($query, $payload, $allowedRelations, $config)->aggregate();
    }

    /**
     * Build query with filters and sorting — return SearchBuilder for manual control.
     *
     * @param array<string> $allowedRelations Relations allowed for $has filtering (role-based whitelist)
     * @param SearchableConfig|null $config Override model's config (useful for adding searchUsing callbacks)
     */
    public static function build(Builder $query, array $payload, array $allowedRelations = [], ?SearchableConfig $config = null): SearchBuilder
    {
        $model = $query->getModel();

        if ($config === null) {
            if (! method_exists($model, 'searchableConfig')) {
                throw new \RuntimeException(get_class($model) . ' must use the Searchable trait and define searchableConfig().');
            }

            $config = $model->searchableConfig();
        }

        // Validate payload
        $validator = new PayloadValidator();
        $validator->validate($payload);

        // Resolve operators
        $resolver = new OperatorResolver();
        $resolvedOperators = $resolver->resolve($model, $config);

        // Parse and apply filters
        $parser = new QueryParser($resolvedOperators, $config, $allowedRelations);
        $parser->apply($query, $payload);

        // Apply eager loading from $has
        $eagerLoad = $parser->getEagerLoad();
        if (! empty($eagerLoad)) {
            $query->with($eagerLoad);
        }

        // Apply sorting
        $sorter = new SortApplier();
        $sorter->apply($query, $payload, $config);

        return new SearchBuilder($query, $payload, $config);
    }
}
