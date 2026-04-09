<?php

namespace Shifton\EloquentSearch\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface CustomFilter
{
    public function apply(Builder $query, string $operator, mixed $value): void;

    /**
     * @return array<string> Operators without $ prefix (e.g. ['in', 'not_in', 'eq'])
     */
    public function allowedOperators(): array;
}
