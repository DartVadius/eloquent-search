<?php

namespace DartVadius\EloquentSearch;

use Illuminate\Database\Eloquent\Builder;
use DartVadius\EloquentSearch\Aggregation\Aggregator;
use DartVadius\EloquentSearch\Pagination\SearchPaginator;

class SearchBuilder
{
    protected Builder $query;
    protected array $payload;
    protected ?SearchableConfig $config;

    public function __construct(Builder $query, array $payload, ?SearchableConfig $config = null)
    {
        $this->query = $query;
        $this->payload = $payload;
        $this->config = $config;
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }

    public function count(): int
    {
        return $this->query->count();
    }

    public function paginate(): array
    {
        return (new SearchPaginator())->paginate($this->query, $this->payload);
    }

    public function get(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->query->get();
    }

    /**
     * Aggregation terminal — GROUP BY + aggregate over the filtered query.
     * Metric/group-by are validated against the model's SearchableConfig (closed set).
     *
     * @param array $spec ['metric' => ['fn' => 'count'|'sum'|'avg'|'min'|'max', 'field' => ?string],
     *                      'groupBy' => null|['field' => string],
     *                      'orderBy' => ?'value'|'group', 'direction' => ?'asc'|'desc', 'limit' => ?int]
     * @return array<array{group?: mixed, value: int|float}>
     */
    public function aggregate(array $spec): array
    {
        if ($this->config === null) {
            throw new \RuntimeException('aggregate() requires a SearchableConfig — use SearchQuery::build().');
        }

        return (new Aggregator())->aggregate($this->query, $this->config, $spec);
    }
}
