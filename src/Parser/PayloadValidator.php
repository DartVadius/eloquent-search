<?php

namespace DartVadius\EloquentSearch\Parser;

use DartVadius\EloquentSearch\Exceptions\InvalidPayloadException;

class PayloadValidator
{
    protected int $maxConditions;
    protected int $maxOrConditions;
    protected int $maxInValues;

    protected const KNOWN_OPERATORS = [
        'eq', 'not_eq', 'in', 'not_in', 'between',
        'gt', 'lt', 'gte', 'lte', 'like', 'is_null',
        'json_contains', 'json_contains_all',
    ];

    public function __construct()
    {
        $this->maxConditions = config('eloquent-search.limits.max_conditions', 50);
        $this->maxOrConditions = config('eloquent-search.limits.max_or_conditions', 10);
        $this->maxInValues = config('eloquent-search.limits.max_in_values', 500);
    }

    public function validate(array $payload): void
    {
        $this->validatePagination($payload);
        $this->validateSort($payload);

        $conditionCount = 0;

        if (isset($payload['where'])) {
            if (! is_array($payload['where'])) {
                throw new InvalidPayloadException('"where" must be an object.');
            }
            $conditionCount += $this->validateWhereBlock($payload['where'], 'where');
        }

        if (isset($payload['or'])) {
            if (! is_array($payload['or'])) {
                throw new InvalidPayloadException('"or" must be an object.');
            }

            if (isset($payload['or']['or'])) {
                throw new InvalidPayloadException('Nested "or" inside "or" is not supported.');
            }

            $conditionCount += $this->validateWhereBlock($payload['or'], 'or');
        }

        if ($conditionCount > $this->maxConditions) {
            throw new InvalidPayloadException("Too many conditions: {$conditionCount} (max: {$this->maxConditions}).");
        }
    }

    protected function validateWhereBlock(array $block, string $context): int
    {
        $count = 0;

        foreach ($block as $operator => $fields) {
            if ($operator === 'and_or') {
                $count += $this->validateAndOr($fields, $context);
                continue;
            }

            if ($operator === 'has') {
                $count += $this->validateHas($fields, $context);
                continue;
            }

            if ($operator === 'search') {
                if ($fields === null || $fields === '') {
                    continue; // empty/null search — skip silently
                }
                if (! is_string($fields)) {
                    throw new InvalidPayloadException('"search" in ' . $context . ' must be a string.');
                }
                $count++;
                continue;
            }

            if (! is_array($fields)) {
                throw new InvalidPayloadException("Operator \"{$operator}\" in {$context} must contain an object of field:value pairs.");
            }

            foreach ($fields as $field => $value) {
                $this->validateOperatorValue($operator, $field, $value, $context);
                $count++;
            }
        }

        return $count;
    }

    protected function validateAndOr(mixed $groups, string $context): int
    {
        if (! is_array($groups)) {
            throw new InvalidPayloadException('"and_or" in ' . $context . ' must be an array.');
        }

        if (count($groups) > $this->maxOrConditions) {
            throw new InvalidPayloadException('Too many "and_or" groups in ' . $context . ': ' . count($groups) . " (max: {$this->maxOrConditions}).");
        }

        $count = 0;

        foreach ($groups as $i => $group) {
            if (! is_array($group)) {
                throw new InvalidPayloadException("\"and_or[{$i}]\" in {$context} must be an object.");
            }

            foreach ($group as $operator => $fields) {
                if ($operator === 'and_or' || $operator === 'or') {
                    throw new InvalidPayloadException("Nested \"{$operator}\" inside and_or is not supported.");
                }

                if (! is_array($fields)) {
                    throw new InvalidPayloadException("Operator \"{$operator}\" in and_or[{$i}] must contain an object.");
                }

                foreach ($fields as $field => $value) {
                    $this->validateOperatorValue($operator, $field, $value, "and_or[{$i}]");
                    $count++;
                }
            }
        }

        return $count;
    }

    protected function validateHas(mixed $relations, string $context): int
    {
        if (! is_array($relations)) {
            throw new InvalidPayloadException('"has" in ' . $context . ' must be an object.');
        }

        $count = 0;

        foreach ($relations as $relationName => $conditions) {
            if (! is_string($relationName)) {
                throw new InvalidPayloadException('"has" keys in ' . $context . ' must be relation names (strings).');
            }

            if (! is_array($conditions)) {
                throw new InvalidPayloadException("\"has.{$relationName}\" in {$context} must be an object.");
            }

            foreach ($conditions as $operator => $fields) {
                // Skip "load" — it's a control flag, not an operator
                if ($operator === 'load') {
                    if (! is_bool($fields)) {
                        throw new InvalidPayloadException("\"has.{$relationName}.load\" must be a boolean.");
                    }
                    continue;
                }

                if ($operator === 'has') {
                    throw new InvalidPayloadException('Nested "has" is not supported.');
                }

                if (! is_array($fields)) {
                    throw new InvalidPayloadException("Operator \"{$operator}\" in has.{$relationName} must contain an object.");
                }

                foreach ($fields as $field => $value) {
                    $this->validateOperatorValue($operator, $field, $value, "has.{$relationName}");
                    $count++;
                }
            }
        }

        return $count;
    }

    protected function validateOperatorValue(string $operator, string $field, mixed $value, string $context): void
    {
        match ($operator) {
            'eq', 'not_eq', 'gt', 'lt', 'gte', 'lte' => $this->assertScalar($value, $operator, $field, $context),
            'in', 'not_in' => $this->assertNonEmptyArray($value, $operator, $field, $context),
            'between' => $this->assertBetween($value, $operator, $field, $context),
            'like' => $this->assertString($value, $operator, $field, $context),
            'is_null' => $this->assertBoolean($value, $operator, $field, $context),
            'json_contains', 'json_contains_all' => $this->assertJsonContains($value, $operator, $field, $context),
            default => null,
        };
    }

    protected function assertScalar(mixed $value, string $operator, string $field, string $context): void
    {
        if (! is_scalar($value) && $value !== null) {
            throw new InvalidPayloadException("{$operator}.{$field} in {$context}: expected scalar, got " . gettype($value) . '.');
        }
    }

    protected function assertNonEmptyArray(mixed $value, string $operator, string $field, string $context): void
    {
        if (! is_array($value) || empty($value)) {
            throw new InvalidPayloadException("{$operator}.{$field} in {$context}: expected non-empty array.");
        }

        if (count($value) > $this->maxInValues) {
            throw new InvalidPayloadException("{$operator}.{$field} in {$context}: too many values " . count($value) . " (max: {$this->maxInValues}).");
        }
    }

    protected function assertBetween(mixed $value, string $operator, string $field, string $context): void
    {
        if (! is_array($value) || count($value) !== 2) {
            throw new InvalidPayloadException("{$operator}.{$field} in {$context}: expected array with exactly 2 elements.");
        }
    }

    protected function assertString(mixed $value, string $operator, string $field, string $context): void
    {
        if (! is_string($value)) {
            throw new InvalidPayloadException("{$operator}.{$field} in {$context}: expected string, got " . gettype($value) . '.');
        }
    }

    protected function assertBoolean(mixed $value, string $operator, string $field, string $context): void
    {
        if (! is_bool($value)) {
            throw new InvalidPayloadException("{$operator}.{$field} in {$context}: expected boolean, got " . gettype($value) . '.');
        }
    }

    protected function assertJsonContains(mixed $value, string $operator, string $field, string $context): void
    {
        if (is_array($value)) {
            if (empty($value)) {
                throw new InvalidPayloadException("{$operator}.{$field} in {$context}: expected non-empty array or scalar.");
            }
            if (count($value) > $this->maxInValues) {
                throw new InvalidPayloadException("{$operator}.{$field} in {$context}: too many values " . count($value) . " (max: {$this->maxInValues}).");
            }
        } elseif (! is_scalar($value)) {
            throw new InvalidPayloadException("{$operator}.{$field} in {$context}: expected array or scalar, got " . gettype($value) . '.');
        }
    }

    protected function validatePagination(array $payload): void
    {
        if (isset($payload['page']) && (! is_int($payload['page']) || $payload['page'] < 1)) {
            throw new InvalidPayloadException('"page" must be a positive integer.');
        }

        if (isset($payload['per_page']) && (! is_int($payload['per_page']) || $payload['per_page'] < 1)) {
            throw new InvalidPayloadException('"per_page" must be a positive integer.');
        }

        if (isset($payload['count_only']) && ! is_bool($payload['count_only'])) {
            throw new InvalidPayloadException('"count_only" must be a boolean.');
        }
    }

    protected function validateSort(array $payload): void
    {
        if (! isset($payload['sort'])) {
            return;
        }

        if (! is_array($payload['sort'])) {
            throw new InvalidPayloadException('"sort" must be an array.');
        }

        foreach ($payload['sort'] as $i => $item) {
            if (! is_array($item) || ! isset($item['field'])) {
                throw new InvalidPayloadException("\"sort[{$i}]\" must be an object with a \"field\" key.");
            }

            if (isset($item['dir']) && ! in_array($item['dir'], ['asc', 'desc'], true)) {
                throw new InvalidPayloadException("\"sort[{$i}].dir\" must be \"asc\" or \"desc\".");
            }
        }
    }
}
