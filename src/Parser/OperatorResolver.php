<?php

namespace DartVadius\EloquentSearch\Parser;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use DartVadius\EloquentSearch\SearchableConfig;

class OperatorResolver
{
    protected const TYPE_OPERATORS = [
        'integer' => ['eq', 'not_eq', 'in', 'not_in', 'gt', 'lt', 'gte', 'lte', 'between'],
        'float' => ['eq', 'not_eq', 'in', 'not_in', 'gt', 'lt', 'gte', 'lte', 'between'],
        'decimal' => ['eq', 'not_eq', 'in', 'not_in', 'gt', 'lt', 'gte', 'lte', 'between'],
        'string' => ['eq', 'not_eq', 'like', 'in', 'not_in'],
        'boolean' => ['eq', 'not_eq'],
        'datetime' => ['eq', 'between', 'gt', 'lt', 'gte', 'lte'],
        'date' => ['eq', 'between', 'gt', 'lt', 'gte', 'lte'],
        'timestamp' => ['eq', 'between', 'gt', 'lt', 'gte', 'lte'],
        'array' => ['json_contains', 'json_contains_all'],
        'json' => ['json_contains', 'json_contains_all'],
    ];

    /**
     * Resolve allowed operators for all fields in the config.
     *
     * @return array<string, array<string>> field => [operators]
     */
    public function resolve(Model $model, SearchableConfig $config): array
    {
        $result = [];

        foreach ($config->getFields() as $key => $value) {
            if (is_int($key)) {
                // Simple field: auto-resolve operators
                $field = $value;
                $result[$field] = $this->resolveForField($model, $field);
            } else {
                // Explicit override: field => [operators]
                $result[$key] = (array) $value;
            }
        }

        // JSON fields always get json_contains + json_contains_all
        foreach ($config->getJsonFields() as $field) {
            if (! isset($result[$field])) {
                $result[$field] = ['json_contains', 'json_contains_all'];
            }
        }

        // Custom filters define their own operators
        foreach ($config->getCustomFilters() as $name => $filter) {
            $result[$name] = $filter->allowedOperators();
        }

        // Add is_null to explicitly configured nullable fields
        foreach ($config->getNullableFields() as $field) {
            if (isset($result[$field]) && ! in_array('is_null', $result[$field], true)) {
                $result[$field][] = 'is_null';
            }
        }

        // Add is_null to nullable fields detected from schema
        $this->addNullableOperators($model, $result);

        return $result;
    }

    protected function resolveForField(Model $model, string $field): array
    {
        $type = $this->getFieldType($model, $field);

        return self::TYPE_OPERATORS[$type] ?? self::TYPE_OPERATORS['string'];
    }

    protected function getFieldType(Model $model, string $field): string
    {
        // Check $casts first
        $casts = $model->getCasts();
        if (isset($casts[$field])) {
            return $this->normalizeCastType($casts[$field]);
        }

        // Fallback to column type from schema
        return $this->getColumnType($model, $field);
    }

    protected function normalizeCastType(string $cast): string
    {
        $cast = strtolower($cast);

        return match (true) {
            in_array($cast, ['int', 'integer']) => 'integer',
            in_array($cast, ['real', 'float', 'double']) => 'float',
            str_starts_with($cast, 'decimal') => 'decimal',
            in_array($cast, ['bool', 'boolean']) => 'boolean',
            in_array($cast, ['date', 'immutable_date']) => 'date',
            in_array($cast, ['datetime', 'immutable_datetime', 'timestamp']) => 'datetime',
            in_array($cast, ['array', 'json', 'collection', 'object']) => 'array',
            default => 'string',
        };
    }

    protected function getColumnType(Model $model, string $field): string
    {
        try {
            $type = Schema::getColumnType($model->getTable(), $field);
        } catch (\Exception) {
            return 'string';
        }

        return match (true) {
            in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint']) => 'integer',
            in_array($type, ['float', 'double', 'decimal']) => 'float',
            in_array($type, ['boolean']) => 'boolean',
            in_array($type, ['date']) => 'date',
            in_array($type, ['datetime', 'timestamp']) => 'datetime',
            in_array($type, ['json']) => 'json',
            default => 'string',
        };
    }

    protected function addNullableOperators(Model $model, array &$result): void
    {
        foreach (array_keys($result) as $field) {
            try {
                $columns = Schema::getColumns($model->getTable());
                foreach ($columns as $column) {
                    if ($column['name'] === $field && $column['nullable']) {
                        $result[$field][] = 'is_null';
                        break;
                    }
                }
            } catch (\Exception) {
                // Schema not available — skip nullable detection
            }
        }
    }
}
