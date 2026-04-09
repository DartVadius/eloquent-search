<?php

namespace DartVadius\EloquentSearch\Filters;

use Illuminate\Database\Eloquent\Builder;

class ComparisonFilter implements FilterInterface
{
    public function __construct(protected string $operator)
    {
    }

    public function apply(Builder $query, string $field, mixed $value): void
    {
        $query->where($field, $this->operator, $value);
    }
}
