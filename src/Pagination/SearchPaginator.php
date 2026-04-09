<?php

namespace DartVadius\EloquentSearch\Pagination;

use Illuminate\Database\Eloquent\Builder;

class SearchPaginator
{
    protected int $defaultPerPage;
    protected int $maxPerPage;

    public function __construct()
    {
        $this->defaultPerPage = config('eloquent-search.pagination.default_per_page', 25);
        $this->maxPerPage = config('eloquent-search.pagination.max_per_page', 1000);
    }

    public function paginate(Builder $query, array $payload): array
    {
        if (! empty($payload['count_only'])) {
            return ['total' => $query->count()];
        }

        $perPage = min($payload['per_page'] ?? $this->defaultPerPage, $this->maxPerPage);
        $page = $payload['page'] ?? 1;

        $result = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $result->items(),
            'total' => $result->total(),
            'page' => $result->currentPage(),
            'per_page' => $result->perPage(),
            'last_page' => $result->lastPage(),
        ];
    }
}
