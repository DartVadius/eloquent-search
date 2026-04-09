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

    public function filter(string $name, CustomFilter $filter): static
    {
        $this->customFilters[$name] = $filter;

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
}
