<?php

namespace DartVadius\EloquentSearch\Filters;

use Illuminate\Database\Eloquent\Builder;

interface FilterInterface
{
    public function apply(Builder $query, string $field, mixed $value): void;
}
