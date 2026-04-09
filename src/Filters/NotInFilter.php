<?php

namespace Shifton\EloquentSearch\Filters;

use Illuminate\Database\Eloquent\Builder;

class NotInFilter implements FilterInterface
{
    public function apply(Builder $query, string $field, mixed $value): void
    {
        $query->whereNotIn($field, (array) $value);
    }
}
