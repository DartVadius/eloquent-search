<?php

namespace DartVadius\EloquentSearch\Filters;

use Illuminate\Database\Eloquent\Builder;

class EqFilter implements FilterInterface
{
    public function apply(Builder $query, string $field, mixed $value): void
    {
        $query->where($field, '=', $value);
    }
}
