<?php

namespace DartVadius\EloquentSearch\Filters;

use Illuminate\Database\Eloquent\Builder;

class JsonContainsAllFilter implements FilterInterface
{
    /**
     * ALL semantics: every value must be contained in the JSON column.
     */
    public function apply(Builder $query, string $field, mixed $value): void
    {
        $values = is_array($value) ? $value : [$value];

        foreach ($values as $v) {
            $query->whereJsonContains($field, $v);
        }
    }
}
