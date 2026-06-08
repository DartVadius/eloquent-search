<?php

namespace DartVadius\EloquentSearch\Aggregation;

use DartVadius\EloquentSearch\Exceptions\InvalidPayloadException;
use DartVadius\EloquentSearch\SearchableConfig;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aggregation terminal: turns a filtered query into grouped aggregate rows.
 *
 * It reuses the query as built by SearchQuery (filters/WHERE already applied) and runs a
 * GROUP BY + aggregate SELECT — a sibling terminal to paginate()/get()/count(), not a step
 * in the filter pipeline. Metrics, dimensions and HAVING references are validated against the
 * model's SearchableConfig (closed set), so no arbitrary columns/SQL reach the database.
 *
 * Capabilities (v1.5.0):
 *  - single metric (`metric`) or many (`metrics: [...]`);
 *  - column metrics (`fn`+`field`) and model-authored expression metrics (`{name}`);
 *  - PHP derived metrics (computed after aggregation from the values row);
 *  - single (`groupBy: {field}`) or multi (`groupBy: [...]`) grouping, on columns or expressions;
 *  - HAVING on metric values (PHP-side, post-aggregation, derived-aware);
 *  - grand total via re-aggregation (correct for non-additive metrics like avg/min/max);
 *  - ordering + top-N limit.
 *
 * Execution model — one path: SQL does filters + GROUP BY + aggregate SELECT + a safety row-cap;
 * ordering, HAVING and limit are applied in PHP. This keeps derived/HAVING correct and the code
 * small. Stays database-agnostic: only standard aggregate functions, GROUP BY, and whatever SQL
 * the MODEL chose to put in its expression metrics/dimensions (never the request).
 *
 * Result shape (mirrors the request):
 *  - `metric` (singular)  → `value`   ;  `metrics` (plural) → `values: {as: number}`
 *  - no groupBy → no `group`  ;  1 groupBy → `group` scalar  ;  N groupBy → `group: {dim: value}`
 *  - `total: true` (with groupBy) → a trailing row with `group: null` = grand total.
 */
class Aggregator
{
    private const FUNCTIONS = ['count', 'sum', 'avg', 'min', 'max'];

    private const HAVING_OPS = ['eq', 'not_eq', 'gt', 'gte', 'lt', 'lte', 'between'];

    public function aggregate(Builder $query, SearchableConfig $config, array $spec): array
    {
        $base = $query->toBase()->reorder();
        $grammar = $base->getGrammar();

        // ── metric axis: singular `metric` (BC) vs plural `metrics` ──
        $plural = array_key_exists('metrics', $spec);
        if ($plural) {
            $entries = $spec['metrics'];
            if (! is_array($entries) || ! array_is_list($entries) || $entries === []) {
                throw new InvalidPayloadException('"metrics" must be a non-empty list.');
            }
        } else {
            $entries = [$spec['metric'] ?? []];
        }

        $resolved = array_map(fn ($e) => $this->resolveMetric($config, (array) $e, $grammar), $entries);

        $hasDerived = (bool) array_filter($resolved, fn ($m) => $m['kind'] === 'derived');

        // SQL select list (key => sql). When derived is requested, every declared expression
        // metric is computed too — closures reference inputs by name and we can't introspect them.
        $sqlSelects = [];
        foreach ($resolved as $m) {
            if ($m['kind'] === 'sql') {
                $sqlSelects[$m['as']] = $m['sql'];
            }
        }
        if ($hasDerived) {
            foreach ($config->getMetricExpressions() as $name => $expr) {
                $sqlSelects[$name] ??= $this->exprToSql($expr, $grammar);
            }
        }
        if ($sqlSelects === []) {
            throw new InvalidPayloadException('Aggregation has no computable metric.');
        }

        // ── group axis ──
        $groups = $this->resolveGroups($config, $spec['groupBy'] ?? null, $grammar);
        $hasGroup = $groups !== [];

        // ── order / having / limit ──
        $orderBy = $spec['orderBy'] ?? null;
        if (! $plural && $orderBy === 'value') {
            $orderBy = $resolved[0]['as']; // legacy alias for the single metric
        }
        $this->assertOrderBy($orderBy, $resolved);
        $direction = $this->direction($spec, $orderBy === 'group' ? 'asc' : 'desc');
        $limit = (isset($spec['limit']) && (int) $spec['limit'] > 0) ? (int) $spec['limit'] : null;
        $having = $this->resolveHaving($resolved, $spec['having'] ?? []);

        $maxGroups = (int) config('eloquent-search.limits.max_groups', 5000);

        // ── SQL: GROUP BY + aggregate select + safety cap ──
        $raw = $this->runQuery($base, $sqlSelects, $groups, $maxGroups);

        // ── shape into intermediate items (group + value lookup) ──
        $items = [];
        foreach ($raw as $row) {
            $items[] = [
                'group' => $hasGroup ? $this->groupValue($row, $groups) : null,
                'lookup' => $this->lookup($row, $sqlSelects, $resolved),
            ];
        }

        // ── HAVING (PHP, post-aggregation, derived-aware) ──
        if ($having !== []) {
            $items = array_values(array_filter($items, fn ($it) => $this->passesHaving($it['lookup'], $having)));
        }

        // ── ordering (PHP) ──
        if ($orderBy !== null && $hasGroup) {
            $items = $this->sortItems($items, $orderBy, $direction, $groups);
        }

        // ── top-N (PHP) ──
        if ($limit !== null) {
            $items = array_slice($items, 0, $limit);
        }

        $out = array_map(fn ($it) => $this->emit($it, $plural, $hasGroup), $items);

        // ── grand total: re-aggregation over the full filtered set (correct for non-additive) ──
        if (! empty($spec['total']) && $hasGroup) {
            $out[] = $this->grandTotal($base, $sqlSelects, $resolved, $plural);
        }

        return $out;
    }

    /**
     * Resolve one metric entry into ['as', 'kind' => 'sql'|'derived', 'sql', 'compute'].
     */
    private function resolveMetric(SearchableConfig $config, array $entry, $grammar): array
    {
        if (array_key_exists('name', $entry)) {
            $name = $entry['name'];
            $as = $entry['as'] ?? $name;

            $expressions = $config->getMetricExpressions();
            if (array_key_exists($name, $expressions)) {
                return ['as' => $as, 'kind' => 'sql', 'sql' => $this->exprToSql($expressions[$name], $grammar), 'compute' => null];
            }

            $derived = $config->getDerived();
            if (array_key_exists($name, $derived)) {
                return ['as' => $as, 'kind' => 'derived', 'sql' => null, 'compute' => $derived[$name]];
            }

            throw new InvalidPayloadException("Unknown metric '{$name}'.");
        }

        if (array_key_exists('fn', $entry)) {
            $fn = strtolower((string) $entry['fn']);

            if (! in_array($fn, self::FUNCTIONS, true)) {
                throw new InvalidPayloadException("Unknown aggregate function: '" . $entry['fn'] . "'.");
            }

            if ($fn === 'count') {
                return ['as' => $entry['as'] ?? 'count', 'kind' => 'sql', 'sql' => 'count(*)', 'compute' => null];
            }

            $field = $entry['field'] ?? null;
            $allowed = $config->getMetrics();

            if (! $field || ! isset($allowed[$field]) || ! in_array($fn, $allowed[$field], true)) {
                throw new InvalidPayloadException("Metric {$fn}(" . ($field ?? '∅') . ') is not allowed.');
            }

            return [
                'as' => $entry['as'] ?? ($fn . '_' . $field),
                'kind' => 'sql',
                'sql' => $fn . '(' . $grammar->wrap($field) . ')',
                'compute' => null,
            ];
        }

        throw new InvalidPayloadException('Metric entry must specify "fn" or "name".');
    }

    /**
     * Normalize the group-by spec (singular object or list) into [['key', 'sql'], ...].
     */
    private function resolveGroups(SearchableConfig $config, mixed $groupBy, $grammar): array
    {
        if (empty($groupBy)) {
            return [];
        }

        $list = (is_array($groupBy) && array_is_list($groupBy)) ? $groupBy : [$groupBy];

        $maxDepth = (int) config('eloquent-search.limits.max_group_by_depth', 3);
        if (count($list) > $maxDepth) {
            throw new InvalidPayloadException('Too many group-by levels: ' . count($list) . " (max: {$maxDepth}).");
        }

        $columnDims = $config->getDimensions();
        $exprDims = $config->getDimensionExpressions();

        $groups = [];
        foreach ($list as $g) {
            $field = is_array($g) ? ($g['field'] ?? null) : $g;
            if (! $field) {
                throw new InvalidPayloadException('groupBy.field is required.');
            }

            if (in_array($field, $columnDims, true)) {
                $groups[] = ['key' => $field, 'sql' => $grammar->wrap($field)];
            } elseif (array_key_exists($field, $exprDims)) {
                $groups[] = ['key' => $field, 'sql' => $this->exprToSql($exprDims[$field], $grammar)];
            } else {
                throw new InvalidPayloadException("groupBy '{$field}' is not a dimension.");
            }
        }

        return $groups;
    }

    private function resolveHaving(array $resolved, mixed $having): array
    {
        if (empty($having)) {
            return [];
        }

        if (! is_array($having) || ! array_is_list($having)) {
            throw new InvalidPayloadException('"having" must be a list of conditions.');
        }

        $names = [];
        foreach ($resolved as $m) {
            $names[$m['as']] = true;
        }

        $out = [];
        foreach ($having as $h) {
            $metric = $h['metric'] ?? null;
            $op = strtolower((string) ($h['op'] ?? ''));

            if (! $metric || ! isset($names[$metric])) {
                throw new InvalidPayloadException("HAVING references unknown metric '" . ($metric ?? '∅') . "'.");
            }
            if (! in_array($op, self::HAVING_OPS, true)) {
                throw new InvalidPayloadException("HAVING operator '{$op}' is not allowed.");
            }
            if (! array_key_exists('value', $h)) {
                throw new InvalidPayloadException("HAVING on '{$metric}' requires a \"value\".");
            }

            $out[] = ['metric' => $metric, 'op' => $op, 'value' => $h['value']];
        }

        return $out;
    }

    private function assertOrderBy(?string $orderBy, array $resolved): void
    {
        if ($orderBy === null || $orderBy === 'group') {
            return;
        }

        foreach ($resolved as $m) {
            if ($m['as'] === $orderBy) {
                return;
            }
        }

        throw new InvalidPayloadException("orderBy '{$orderBy}' is not a selected metric or 'group'.");
    }

    private function runQuery($base, array $sqlSelects, array $groups, int $maxGroups): array
    {
        $select = [];
        $i = 0;
        foreach ($sqlSelects as $sql) {
            $select[] = DB::raw($sql . ' as m' . $i);
            $i++;
        }
        foreach ($groups as $k => $g) {
            $select[] = DB::raw($g['sql'] . ' as g' . $k);
        }

        $q = clone $base;
        $q->select($select);

        if ($groups !== []) {
            $q->groupBy(array_map(fn ($g) => DB::raw($g['sql']), $groups));
        }

        return $q->limit($maxGroups)->get()->all();
    }

    /** @return array<string, int|float> values keyed by metric name (sql first, then derived) */
    private function lookup(object $row, array $sqlSelects, array $resolved): array
    {
        $all = [];
        $i = 0;
        foreach ($sqlSelects as $key => $_) {
            $all[$key] = $this->number($row->{'m' . $i} ?? null);
            $i++;
        }
        foreach ($resolved as $m) {
            if ($m['kind'] === 'derived') {
                $all[$m['as']] = $this->number(($m['compute'])($all));
            }
        }

        // expose only the requested metrics, in request order
        $out = [];
        foreach ($resolved as $m) {
            $out[$m['as']] = $all[$m['as']];
        }

        return $out;
    }

    private function groupValue(object $row, array $groups): mixed
    {
        if (count($groups) === 1) {
            return $row->g0;
        }

        $out = [];
        foreach ($groups as $k => $g) {
            $out[$g['key']] = $row->{'g' . $k};
        }

        return $out;
    }

    private function passesHaving(array $lookup, array $having): bool
    {
        foreach ($having as $h) {
            $v = $lookup[$h['metric']] ?? null;
            if ($v === null || ! is_numeric($v)) {
                return false;
            }
            $v = (float) $v;
            $value = $h['value'];

            $ok = match ($h['op']) {
                'eq' => $v == (float) $value,
                'not_eq' => $v != (float) $value,
                'gt' => $v > (float) $value,
                'gte' => $v >= (float) $value,
                'lt' => $v < (float) $value,
                'lte' => $v <= (float) $value,
                'between' => is_array($value) && count($value) === 2 && $v >= (float) $value[0] && $v <= (float) $value[1],
                default => false,
            };

            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private function sortItems(array $items, string $orderBy, string $direction, array $groups): array
    {
        $factor = $direction === 'asc' ? 1 : -1;

        usort($items, function ($a, $b) use ($orderBy, $factor, $groups) {
            return ($this->sortKey($a, $orderBy, $groups) <=> $this->sortKey($b, $orderBy, $groups)) * $factor;
        });

        return $items;
    }

    private function sortKey(array $item, string $orderBy, array $groups): mixed
    {
        if ($orderBy === 'group') {
            return count($groups) === 1 ? $item['group'] : array_values($item['group']);
        }

        return $item['lookup'][$orderBy] ?? null;
    }

    private function emit(array $item, bool $plural, bool $hasGroup): array
    {
        $rec = [];
        if ($hasGroup) {
            $rec['group'] = $item['group'];
        }
        if ($plural) {
            $rec['values'] = $item['lookup'];
        } else {
            $rec['value'] = reset($item['lookup']);
        }

        return $rec;
    }

    private function grandTotal($base, array $sqlSelects, array $resolved, bool $plural): array
    {
        $select = [];
        $i = 0;
        foreach ($sqlSelects as $sql) {
            $select[] = DB::raw($sql . ' as m' . $i);
            $i++;
        }

        $q = clone $base;
        $row = $q->select($select)->first() ?? (object) [];

        return $this->emit(
            ['group' => null, 'lookup' => $this->lookup($row, $sqlSelects, $resolved)],
            $plural,
            true
        );
    }

    private function exprToSql(mixed $expr, $grammar): string
    {
        if ($expr instanceof Expression) {
            return (string) $expr->getValue($grammar);
        }

        return (string) $expr;
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
