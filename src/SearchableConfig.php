<?php

namespace DartVadius\EloquentSearch;

use DartVadius\EloquentSearch\Contracts\CustomFilter;

class SearchableConfig
{
    protected array $fields = [];
    protected array $jsonFields = [];
    protected array $sortableFields = [];
    protected ?string $defaultSortField = null;
    protected string $defaultSortDir = 'asc';
    protected array $customFilters = [];
    protected array $relationFields = [];
    protected array $searchFields = [];
    protected array $searchCallbacks = [];
    protected array $nullableFields = [];
    protected array $metrics = [];
    protected array $metricExpressions = [];
    protected array $dimensions = [];
    protected array $dimensionExpressions = [];
    protected array $derived = [];

    public static function make(): static
    {
        return new static();
    }

    /**
     * @param array<int|string, string|array> $fields
     */
    public function fields(array $fields): static
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * @param array<string> $fields
     */
    public function jsonFields(array $fields): static
    {
        $this->jsonFields = $fields;

        return $this;
    }

    /**
     * @param array<string> $fields
     */
    public function sortable(array $fields): static
    {
        $this->sortableFields = $fields;

        return $this;
    }

    public function defaultSort(string $field, string $dir = 'asc'): static
    {
        $this->defaultSortField = $field;
        $this->defaultSortDir = $dir;

        return $this;
    }

    /**
     * @param array<string, array<string>> $relations relation name => [allowed fields]
     */
    public function relations(array $relations): static
    {
        $this->relationFields = $relations;

        return $this;
    }

    /**
     * Fields for $search operator. Supports dot notation for relations: 'employee.first_name'
     *
     * @param array<string> $fields
     */
    public function searchFields(array $fields): static
    {
        $this->searchFields = $fields;

        return $this;
    }

    /**
     * Register a callback for the $search operator.
     * Called inside the OR group alongside searchFields.
     *
     * Signature: function (Builder $query, string $term): void
     * - $query is scoped inside orWhere, so use $query->orWhere / orWhereIn / orWhereHas
     * - $term is the raw search string (not escaped)
     *
     * @param \Closure $callback
     */
    public function searchUsing(\Closure $callback): static
    {
        $this->searchCallbacks[] = $callback;

        return $this;
    }

    /**
     * @param array<string> $fields
     */
    public function nullable(array $fields): static
    {
        $this->nullableFields = $fields;

        return $this;
    }

    public function filter(string $name, CustomFilter $filter): static
    {
        $this->customFilters[$name] = $filter;

        return $this;
    }

    /**
     * Fields/expressions that may be aggregated.
     *
     * Two declaration shapes (mixed freely):
     *  - Column metric: `field => ['sum','avg','min','max']` — `fn(field)` over a numeric column.
     *  - Expression metric: `name => ['expr' => DB::raw('SUM(CASE ...)')]` — a model-authored
     *    aggregate expression referenced by name from the payload (`{name: ...}`). The SQL lives
     *    in the model (trusted), never in the request — the closed-set guarantee is preserved.
     *
     * `count` (of records) is always available and needs no field.
     *
     * @param array<string, array<string>|array{expr: mixed}> $metrics
     */
    public function metrics(array $metrics): static
    {
        $columns = [];
        $expressions = [];

        foreach ($metrics as $key => $def) {
            if (is_array($def) && array_is_list($def)) {
                $columns[$key] = $def;
            } elseif (is_array($def) && array_key_exists('expr', $def)) {
                $expressions[$key] = $def['expr'];
            } else {
                throw new \InvalidArgumentException(
                    "Invalid metric definition for '{$key}': expected a list of functions or ['expr' => ...]."
                );
            }
        }

        $this->metrics = $columns;
        $this->metricExpressions = $expressions;

        return $this;
    }

    /**
     * Fields/expressions allowed as a GROUP BY dimension.
     *
     * Two shapes (mixed freely in one array):
     *  - Column dimension: a string list entry, e.g. `'status'`.
     *  - Expression dimension: `name => DB::raw('DATE(scheduled_at)')` — a model-authored group
     *    expression (the portable escape hatch for period/weekday/etc.; the SQL is model code).
     *
     * @param array<int|string, string|mixed> $dimensions
     */
    public function dimensions(array $dimensions): static
    {
        $columns = [];
        $expressions = [];

        foreach ($dimensions as $key => $val) {
            if (is_int($key)) {
                $columns[] = $val;
            } else {
                $expressions[$key] = $val;
            }
        }

        $this->dimensions = $columns;
        $this->dimensionExpressions = $expressions;

        return $this;
    }

    /**
     * Derived metrics: computed in PHP after aggregation from the row of base/expression metrics.
     * Each closure receives the values map (keyed by metric name) and returns a number.
     * When any derived metric is requested, every declared expression metric is computed so the
     * closure can read its inputs by name.
     *
     * @param array<string, \Closure> $derived name => fn(array $values): int|float
     */
    public function derived(array $derived): static
    {
        $this->derived = $derived;

        return $this;
    }

    public function getFields(): array
    {
        return $this->fields;
    }

    public function getJsonFields(): array
    {
        return $this->jsonFields;
    }

    public function getSortableFields(): array
    {
        return $this->sortableFields;
    }

    public function getDefaultSort(): ?array
    {
        if ($this->defaultSortField === null) {
            return null;
        }

        return ['field' => $this->defaultSortField, 'dir' => $this->defaultSortDir];
    }

    public function getCustomFilters(): array
    {
        return $this->customFilters;
    }

    public function hasCustomFilter(string $name): bool
    {
        return isset($this->customFilters[$name]);
    }

    public function getCustomFilter(string $name): CustomFilter
    {
        return $this->customFilters[$name];
    }

    public function getRelationFields(): array
    {
        return $this->relationFields;
    }

    public function hasRelation(string $name): bool
    {
        return isset($this->relationFields[$name]);
    }

    public function getRelationAllowedFields(string $name): array
    {
        return $this->relationFields[$name] ?? [];
    }

    public function getNullableFields(): array
    {
        return $this->nullableFields;
    }

    public function getSearchFields(): array
    {
        return $this->searchFields;
    }

    /**
     * @return array<\Closure>
     */
    public function getSearchCallbacks(): array
    {
        return $this->searchCallbacks;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getMetricExpressions(): array
    {
        return $this->metricExpressions;
    }

    public function getDimensions(): array
    {
        return $this->dimensions;
    }

    public function getDimensionExpressions(): array
    {
        return $this->dimensionExpressions;
    }

    /** @return array<string, \Closure> */
    public function getDerived(): array
    {
        return $this->derived;
    }
}
