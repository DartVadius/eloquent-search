<?php

namespace DartVadius\EloquentSearch\Filters;

use Illuminate\Database\Eloquent\Builder;

class InFilter implements FilterInterface
{
    public function apply(Builder $query, string $field, mixed $value): void
    {
        $query->whereIn($field, (array) $value);
    }
}
