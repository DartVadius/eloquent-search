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

        return new SearchBuilder($query, $payload);
    }
}
