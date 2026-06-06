<?php

namespace DartVadius\EloquentSearch\Aggregation;

use DartVadius\EloquentSearch\Exceptions\InvalidPayloadException;
use DartVadius\EloquentSearch\SearchableConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aggregation terminal: turns a filtered query into grouped aggregate rows.
 *
 * It reuses the query as built by SearchQuery (filters/WHERE already applied) and runs a
 * GROUP BY + aggregate SELECT — a sibling terminal to paginate()/get()/count(), not a step
 * in the filter pipeline. Metric and group-by are validated against the model's
 * SearchableConfig (closed set), so no arbitrary columns/SQL reach the database.
 *
 * Stays database-agnostic: only standard aggregate functions and GROUP BY on a column
 * (quoted via the connection's grammar). Date-bucket grouping is intentionally NOT here —
 * it has no portable SQL form and belongs to the (driver-aware) consumer.
 *
 * Result shape: [['group' => <value>, 'value' => <number>], ...] when grouped,
 * or [['value' => <number>]] for a single scalar (no group-by).
 */
class Aggregator
{
    private const FUNCTIONS = ['count', 'sum', 'avg', 'min', 'max'];

    public function aggregate(Builder $query, SearchableConfig $config, array $spec): array
    {
        $metric = $this->resolveMetric($config, $spec['metric'] ?? []);
        $groupBy = $this->resolveGroupBy($config, $spec['groupBy'] ?? null);

        // operate on the base query (WHERE/scopes preserved); drop the default sort, which
        // would reference a non-grouped column and break GROUP BY
        $base = $query->toBase()->reorder();
        $grammar = $base->getGrammar();

        $valueExpr = $this->valueExpression($metric, $grammar);

        if ($groupBy === null) {
            $row = $base->select([DB::raw("{$valueExpr} as agg_value")])->first();

            return [['value' => $this->number($row->agg_value ?? 0)]];
        }

        $groupExpr = $grammar->wrap($groupBy['field']);

        $base->select([
            DB::raw("{$groupExpr} as agg_group"),
            DB::raw("{$valueExpr} as agg_value"),
        ])->groupBy($groupBy['field']);

        $order = $spec['orderBy'] ?? null;
        if ($order === 'value') {
            $base->orderByRaw("{$valueExpr} " . $this->direction($spec, 'desc'));
        } elseif ($order === 'group') {
            $base->orderBy($groupBy['field'], $this->direction($spec, 'asc'));
        }

        if (isset($spec['limit']) && (int) $spec['limit'] > 0) {
            $base->limit((int) $spec['limit']);
        }

        return $base->get()->map(fn ($r) => [
            'group' => $r->agg_group,
            'value' => $this->number($r->agg_value),
        ])->all();
    }

    /** @return array{fn: string, field: ?string} */
    private function resolveMetric(SearchableConfig $config, array $metric): array
    {
        $fn = strtolower((string) ($metric['fn'] ?? ''));

        if (! in_array($fn, self::FUNCTIONS, true)) {
            throw new InvalidPayloadException("Unknown aggregate function: '" . ($metric['fn'] ?? 'null') . "'");
        }

        if ($fn === 'count') {
            return ['fn' => 'count', 'field' => null];
        }

        $field = $metric['field'] ?? null;
        $allowed = $config->getMetrics();

        if (! $field || ! isset($allowed[$field]) || ! in_array($fn, $allowed[$field], true)) {
            throw new InvalidPayloadException("Metric {$fn}(" . ($field ?? '∅') . ") is not allowed");
        }

        return ['fn' => $fn, 'field' => $field];
    }

    /** @return array{field: string}|null */
    private function resolveGroupBy(SearchableConfig $config, mixed $groupBy): ?array
    {
        if (empty($groupBy)) {
            return null;
        }

        $field = $groupBy['field'] ?? null;
        if (! $field) {
            throw new InvalidPayloadException('groupBy.field is required');
        }

        if (! in_array($field, $config->getDimensions(), true)) {
            throw new InvalidPayloadException("groupBy '{$field}' is not a dimension");
        }

        return ['field' => $field];
    }

    private function valueExpression(array $metric, $grammar): string
    {
        if ($metric['fn'] === 'count') {
            return 'count(*)';
        }

        return "{$metric['fn']}(" . $grammar->wrap($metric['field']) . ')';
    }

    private function direction(array $spec, string $default): string
    {
        return strtolower((string) ($spec['direction'] ?? $default)) === 'asc' ? 'asc' : 'desc';
    }

    private function number(mixed $value): int|float
    {
        if ($value === null) {
            return 0;
        }

        return $value + 0;
    }
}
