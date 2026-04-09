<?php

namespace Shifton\EloquentSearch;

use Illuminate\Database\Eloquent\Builder;
use Shifton\EloquentSearch\Pagination\SearchPaginator;

class SearchBuilder
{
    protected Builder $query;
    protected array $payload;

    public function __construct(Builder $query, array $payload)
    {
        $this->query = $query;
        $this->payload = $payload;
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }

    public function count(): int
    {
        return $this->query->count();
    }

    public function paginate(): array
    {
        return (new SearchPaginator())->paginate($this->query, $this->payload);
    }

    public function get(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->query->get();
    }
}
