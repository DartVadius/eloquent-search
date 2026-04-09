<?php

namespace Shifton\EloquentSearch\Filters;

use Illuminate\Database\Eloquent\Builder;

class NotEqFilter implements FilterInterface
{
    public function apply(Builder $query, string $field, mixed $value): void
    {
        $query->where($field, '!=', $value);
    }
}
