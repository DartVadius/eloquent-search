<?php

namespace DartVadius\EloquentSearch\Filters;

use Illuminate\Database\Eloquent\Builder;

class IsNullFilter implements FilterInterface
{
    public function apply(Builder $query, string $field, mixed $value): void
    {
        if ($value === true) {
            $query->whereNull($field);
        } else {
            $query->whereNotNull($field);
        }
    }
}
