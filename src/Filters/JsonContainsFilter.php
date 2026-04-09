<?php

namespace DartVadius\EloquentSearch\Filters;

use Illuminate\Database\Eloquent\Builder;

class JsonContainsFilter implements FilterInterface
{
    /**
     * ANY semantics: at least one value from the array is contained in the JSON column.
     */
    public function apply(Builder $query, string $field, mixed $value): void
    {
        $values = is_array($value) ? $value : [$value];

        if (count($values) === 1) {
            $query->whereJsonContains($field, $values[0]);
        } else {
            $query->where(function (Builder $q) use ($field, $values) {
                foreach ($values as $v) {
                    $q->orWhereJsonContains($field, $v);
                }
            });
        }
    }
}
