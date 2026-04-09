<?php

namespace DartVadius\EloquentSearch\Parser;

use Illuminate\Database\Eloquent\Builder;
use DartVadius\EloquentSearch\Exceptions\InvalidPayloadException;
use DartVadius\EloquentSearch\Filters\BetweenFilter;
use DartVadius\EloquentSearch\Filters\ComparisonFilter;
use DartVadius\EloquentSearch\Filters\EqFilter;
use DartVadius\EloquentSearch\Filters\FilterInterface;
use DartVadius\EloquentSearch\Filters\InFilter;
use DartVadius\EloquentSearch\Filters\IsNullFilter;
use DartVadius\EloquentSearch\Filters\JsonContainsAllFilter;
use DartVadius\EloquentSearch\Filters\JsonContainsFilter;
use DartVadius\EloquentSearch\Filters\LikeFilter;
use DartVadius\EloquentSearch\Filters\NotEqFilter;
use DartVadius\EloquentSearch\Filters\NotInFilter;
use DartVadius\EloquentSearch\SearchableConfig;

class QueryParser
{
    protected array $resolvedOperators;
    protected SearchableConfig $config;
    protected string $onUnknownField;
    protected array $allowedRelations;
    protected array $eagerLoad = [];

    protected static array $filterMap = [
        'eq' => EqFilter::class,
        'not_eq' => NotEqFilter::class,
        'in' => InFilter::class,
        'not_in' => NotInFilter::class,
        'between' => BetweenFilter::class,
        'gt' => null,
        'lt' => null,
        'gte' => null,
        'lte' => null,
        'like' => LikeFilter::class,
        'is_null' => IsNullFilter::class,
        'json_contains' => JsonContainsFilter::class,
        'json_contains_all' => JsonContainsAllFilter::class,
    ];

    public function __construct(
        array $resolvedOperators,
        SearchableConfig $config,
        array $allowedRelations = [],
    ) {
        $this->resolvedOperators = $resolvedOperators;
        $this->config = $config;
        $this->allowedRelations = $allowedRelations;
        $this->onUnknownField = config('eloquent-search.on_unknown_field', 'skip');
    }

    public function apply(Builder $query, array $payload): void
    {
        $hasWhere = isset($payload['where']) && ! empty($payload['where']);
        $hasOr = isset($payload['or']) && ! empty($payload['or']);

        if ($hasWhere && $hasOr) {
            $query->where(function (Builder $q) use ($payload) {
                $q->where(function (Builder $inner) use ($payload) {
                    $this->applyWhereBlock($inner, $payload['where']);
                })->orWhere(function (Builder $inner) use ($payload) {
                    $this->applyWhereBlock($inner, $payload['or']);
                });
            });
        } elseif ($hasWhere) {
            $this->applyWhereBlock($query, $payload['where']);
        } elseif ($hasOr) {
            $this->applyWhereBlock($query, $payload['or']);
        }
    }

    protected function applyWhereBlock(Builder $query, array $block): void
    {
        foreach ($block as $operator => $fields) {
            if ($operator === 'and_or') {
                $this->applyAndOr($query, $fields);
                continue;
            }

            if ($operator === 'has') {
                $this->applyHas($query, $fields);
                continue;
            }

            if ($operator === 'search') {
                if (is_string($fields) && $fields !== '') {
                    $this->applySearch($query, $fields);
                }
                continue;
            }

            if (! is_array($fields)) {
                continue;
            }

            foreach ($fields as $field => $value) {
                $this->applyCondition($query, $operator, $field, $value);
            }
        }
    }

    protected function applyHas(Builder $query, array $relations): void
    {
        foreach ($relations as $relationName => $conditions) {
            if (! empty($this->allowedRelations) && ! in_array($relationName, $this->allowedRelations, true)) {
                continue;
            }

            if (! $this->config->hasRelation($relationName)) {
                continue;
            }

            $allowedFields = $this->config->getRelationAllowedFields($relationName);

            $shouldLoad = $conditions['load'] ?? true;

            $operators = array_filter($conditions, fn ($key) => $key !== 'load', ARRAY_FILTER_USE_KEY);

            if (empty($operators)) {
                continue;
            }

            $query->whereHas($relationName, function (Builder $relationQuery) use ($operators, $allowedFields) {
                foreach ($operators as $operator => $fields) {
                    if (! is_array($fields)) {
                        continue;
                    }
                    foreach ($fields as $field => $value) {
                        if (! in_array($field, $allowedFields, true)) {
                            continue;
                        }

                        $filter = $this->resolveFilter($operator);
                        if ($filter === null) {
                            continue;
                        }
                        $filter->apply($relationQuery, $field, $value);
                    }
                }
            });

            if ($shouldLoad) {
                $this->eagerLoad[] = $relationName;
            }
        }
    }

    public function getEagerLoad(): array
    {
        return array_unique($this->eagerLoad);
    }

    protected function applySearch(Builder $query, string $term): void
    {
        $searchFields = $this->config->getSearchFields();
        $searchCallbacks = $this->config->getSearchCallbacks();

        if (empty($searchFields) && empty($searchCallbacks)) {
            return;
        }

        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

        $directFields = [];
        $relationGroups = [];

        foreach ($searchFields as $field) {
            if (str_contains($field, '.')) {
                [$relation, $relationField] = explode('.', $field, 2);
                $relationGroups[$relation][] = $relationField;
            } else {
                $directFields[] = $field;
            }
        }

        $query->where(function (Builder $q) use ($directFields, $relationGroups, $escaped, $searchCallbacks, $term) {
            foreach ($directFields as $field) {
                $grammar = $q->getQuery()->getGrammar();
                $wrappedField = $grammar->wrap($field);
                $q->orWhereRaw("{$wrappedField} LIKE ? ESCAPE '!'", ["%{$escaped}%"]);
            }

            foreach ($relationGroups as $relation => $fields) {
                $q->orWhereHas($relation, function (Builder $relationQuery) use ($fields, $escaped) {
                    $relationQuery->where(function (Builder $inner) use ($fields, $escaped) {
                        foreach ($fields as $field) {
                            $grammar = $inner->getQuery()->getGrammar();
                            $wrappedField = $grammar->wrap($field);
                            $inner->orWhereRaw("{$wrappedField} LIKE ? ESCAPE '!'", ["%{$escaped}%"]);
                        }
                    });
                });
            }

            foreach ($searchCallbacks as $callback) {
                $callback($q, $term);
            }
        });
    }

    protected function applyCondition(Builder $query, string $operator, string $field, mixed $value): void
    {
        // Check if field has a custom filter
        if ($this->config->hasCustomFilter($field)) {
            $customFilter = $this->config->getCustomFilter($field);
            if (in_array($operator, $customFilter->allowedOperators(), true)) {
                $customFilter->apply($query, $operator, $value);
                return;
            }
            return;
        }

        // Check whitelist
        if (! isset($this->resolvedOperators[$field])) {
            if ($this->onUnknownField === 'throw') {
                throw new InvalidPayloadException("Unknown field: \"{$field}\".");
            }
            return;
        }

        // Check if operator is allowed for this field
        if (! in_array($operator, $this->resolvedOperators[$field], true)) {
            return;
        }

        $filter = $this->resolveFilter($operator);
        if ($filter === null) {
            return;
        }

        $filter->apply($query, $field, $value);
    }

    protected function applyAndOr(Builder $query, array $groups): void
    {
        foreach ($groups as $group) {
            $query->where(function (Builder $q) use ($group) {
                $first = true;
                foreach ($group as $operator => $fields) {
                    if (! is_array($fields)) {
                        continue;
                    }
                    foreach ($fields as $field => $value) {
                        if ($first) {
                            $this->applyConditionInContext($q, $operator, $field, $value, 'and');
                            $first = false;
                        } else {
                            $this->applyConditionInContext($q, $operator, $field, $value, 'or');
                        }
                    }
                }
            });
        }
    }

    protected function applyConditionInContext(Builder $query, string $operator, string $field, mixed $value, string $boolean): void
    {
        // Custom filter
        if ($this->config->hasCustomFilter($field)) {
            $customFilter = $this->config->getCustomFilter($field);
            if (in_array($operator, $customFilter->allowedOperators(), true)) {
                if ($boolean === 'or') {
                    $query->orWhere(function (Builder $q) use ($customFilter, $operator, $value) {
                        $customFilter->apply($q, $operator, $value);
                    });
                } else {
                    $customFilter->apply($query, $operator, $value);
                }
            }
            return;
        }

        // Whitelist check
        if (! isset($this->resolvedOperators[$field])) {
            return;
        }

        if (! in_array($operator, $this->resolvedOperators[$field], true)) {
            return;
        }

        $filter = $this->resolveFilter($operator);
        if ($filter === null) {
            return;
        }

        if ($boolean === 'or') {
            $query->orWhere(function (Builder $q) use ($filter, $field, $value) {
                $filter->apply($q, $field, $value);
            });
        } else {
            $filter->apply($query, $field, $value);
        }
    }

    protected function resolveFilter(string $operator): ?FilterInterface
    {
        if (in_array($operator, ['gt', 'lt', 'gte', 'lte'])) {
            $sqlOperator = match ($operator) {
                'gt' => '>',
                'lt' => '<',
                'gte' => '>=',
                'lte' => '<=',
            };
            return new ComparisonFilter($sqlOperator);
        }

        $class = self::$filterMap[$operator] ?? null;
        if ($class === null) {
            return null;
        }

        return new $class();
    }
}
