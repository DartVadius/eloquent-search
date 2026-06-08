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
     *
     * The spec travels inside the payload under the `aggregate` key (the whole JSON goes
     * into the builder, consistent with the rest of the DSL). Metrics, dimensions and HAVING
     * references are validated against the model's SearchableConfig (closed set).
     *
     * payload['aggregate'] (all keys optional unless noted):
     *   'metric'   => ['fn' => 'count'|'sum'|'avg'|'min'|'max', 'field' => ?string]   // one metric (BC)
     *   'metrics'  => [ {fn,field?,as?} | {name,as?}, ... ]                            // many metrics
     *   'groupBy'  => ['field' => string] | [ ['field'=>...], ... ] | null            // single / multi
     *   'having'   => [ ['metric'=>'<as>', 'op'=>'eq|not_eq|gt|gte|lt|lte|between', 'value'=>...] ]
     *   'orderBy'  => 'group'|'<as>' (|'value' legacy), 'direction' => 'asc'|'desc', 'limit' => int
     *   'total'    => bool                                                            // append grand-total row
     *
     * @return array<array{group?: mixed, value?: int|float, values?: array<string,int|float>}>
     */
    public function aggregate(): array
    {
        if ($this->config === null) {
            throw new \RuntimeException('aggregate() requires a SearchableConfig — use SearchQuery::build().');
        }

        return (new Aggregator())->aggregate($this->query, $this->config, $this->payload['aggregate'] ?? []);
    }
}
