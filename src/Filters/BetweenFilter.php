<?php

namespace DartVadius\EloquentSearch\Filters;

use Illuminate\Database\Eloquent\Builder;

class BetweenFilter implements FilterInterface
{
    public function apply(Builder $query, string $field, mixed $value): void
    {
        $query->whereBetween($field, $value);
    }
}
