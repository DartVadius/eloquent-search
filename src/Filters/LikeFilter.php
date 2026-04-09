<?php

namespace DartVadius\EloquentSearch\Filters;

use Illuminate\Database\Eloquent\Builder;

class LikeFilter implements FilterInterface
{
    public function apply(Builder $query, string $field, mixed $value): void
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
        $grammar = $query->getQuery()->getGrammar();
        $wrappedField = $grammar->wrap($field);
        $query->whereRaw("{$wrappedField} LIKE ? ESCAPE '!'", ["%{$escaped}%"]);
    }
}
