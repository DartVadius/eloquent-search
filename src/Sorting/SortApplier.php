<?php

namespace DartVadius\EloquentSearch\Sorting;

use Illuminate\Database\Eloquent\Builder;
use DartVadius\EloquentSearch\SearchableConfig;

class SortApplier
{
    public function apply(Builder $query, array $payload, SearchableConfig $config): void
    {
        $sortableFields = $config->getSortableFields();
        $applied = false;

        if (isset($payload['sort']) && is_array($payload['sort'])) {
            foreach ($payload['sort'] as $item) {
                $field = $item['field'] ?? null;
                $dir = $item['dir'] ?? 'asc';

                if ($field && in_array($field, $sortableFields, true)) {
                    $query->orderBy($field, $dir);
                    $applied = true;
                }
            }
        }

        if (! $applied) {
            $default = $config->getDefaultSort();
            if ($default) {
                $query->orderBy($default['field'], $default['dir']);
            }
        }
    }
}
