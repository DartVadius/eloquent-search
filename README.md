# Eloquent Search

Universal JSON query DSL parser for Laravel Eloquent. Accepts a structured JSON payload and converts it into Eloquent queries with filtering, sorting, pagination, full-text search, relation filtering, and JSON field support.

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [How It Works](#how-it-works)
- [Model Configuration](#model-configuration)
  - [Fields](#fields)
  - [JSON Fields](#json-fields)
  - [Sorting](#sorting)
  - [Search Fields](#search-fields)
  - [Extending Search (searchUsing)](#extending-search-searchusing)
  - [Relations](#relations)
  - [Custom Filters](#custom-filters)
- [JSON Payload Format](#json-payload-format)
  - [Operators](#operators)
  - [Sorting](#sorting-1)
  - [Pagination](#pagination)
  - [Count Only](#count-only)
  - [Full-Text Search](#full-text-search)
  - [OR Conditions](#or-conditions)
  - [AND-OR Groups](#and-or-groups)
  - [Relation Filtering (has)](#relation-filtering-has)
- [API Reference](#api-reference)
  - [SearchQuery::apply()](#searchqueryapply)
  - [SearchQuery::build()](#searchquerybuild)
  - [SearchBuilder](#searchbuilder)
- [Operator Auto-Resolution](#operator-auto-resolution)
- [Custom Filters](#custom-filters-1)
- [Configuration](#configuration)
- [Validation & Error Handling](#validation--error-handling)
- [Security Considerations](#security-considerations)

## Requirements

- PHP >= 8.2
- Laravel >= 11.0

## Installation

Add the package to your project via Composer:

```bash
composer require dartvadius/eloquent-search
```

Laravel auto-discovers the service provider. To publish the config file:

```bash
php artisan vendor:publish --tag=eloquent-search-config
```

This creates `config/eloquent-search.php` with default settings.

## Quick Start

### 1. Prepare your model

Add the `Searchable` trait and define `searchableConfig()`:

```php
use DartVadius\EloquentSearch\Searchable;
use DartVadius\EloquentSearch\SearchableConfig;

class Task extends Model
{
    use Searchable;

    public function searchableConfig(): SearchableConfig
    {
        return SearchableConfig::make()
            ->fields(['id', 'title', 'employee_id', 'scheduled_time', 'created_at'])
            ->sortable(['id', 'scheduled_time', 'created_at'])
            ->defaultSort('scheduled_time', 'asc');
    }
}
```

### 2. Use in a controller

```php
use DartVadius\EloquentSearch\SearchQuery;

public function search(Request $request)
{
    $query = Task::where('company_id', $request->user()->company_id);

    $result = SearchQuery::apply($query, $request->json()->all());

    return response()->json($result);
}
```

### 3. Send a JSON request

```http
POST /api/tasks/search
Content-Type: application/json

{
    "where": {
        "eq": { "employee_id": 42 },
        "between": { "scheduled_time": ["2026-04-01 00:00:00", "2026-04-30 23:59:59"] }
    },
    "sort": [{ "field": "scheduled_time", "dir": "desc" }],
    "page": 1,
    "per_page": 25
}
```

Response:

```json
{
    "data": [{ "id": 1, "title": "...", "..." }],
    "total": 42,
    "page": 1,
    "per_page": 25,
    "last_page": 2
}
```

## How It Works

```
JSON payload
    |
    v
PayloadValidator        -- validates structure, types, limits
    |
    v
OperatorResolver        -- reads model casts/schema, resolves allowed operators per field
    |
    v
QueryParser             -- converts JSON operators into Eloquent where/whereIn/etc. calls
    |
    v
SortApplier             -- applies sorting or default sort
    |
    v
SearchPaginator         -- paginates or returns count_only
    |
    v
Eloquent Builder result
```

The package never touches your base query. You set up any authorization scopes, joins, or conditions you need *before* passing the Builder to `SearchQuery`. The DSL is applied on top.

## Model Configuration

The `searchableConfig()` method returns a `SearchableConfig` instance that defines what's allowed and how.

### Fields

The field whitelist controls which columns can be filtered. Fields not in this list are silently ignored (or throw an exception, depending on config).

```php
SearchableConfig::make()
    ->fields([
        'id',                        // auto-resolves operators from column type
        'title',                     // string -> eq, not_eq, like, in, not_in
        'employee_id',               // integer -> eq, not_eq, in, not_in, gt, lt, gte, lte, between
        'scheduled_time',            // datetime -> eq, between, gt, lt, gte, lte
        'is_recurring',              // boolean -> eq, not_eq
        'status' => ['eq', 'in'],    // explicit override: only these operators allowed
    ]);
```

Operators are auto-resolved from the model's `$casts` or the database schema. See [Operator Auto-Resolution](#operator-auto-resolution) for the full mapping.

**Nullable columns** automatically get the `is_null` operator added.

### JSON Fields

Mark columns that store JSON arrays (e.g., tags, marks, skills):

```php
->jsonFields(['marks', 'skills'])
```

This enables the `json_contains` and `json_contains_all` operators for these fields, regardless of their `$casts` type.

### Sorting

Define which fields can appear in the `sort` payload:

```php
->sortable(['id', 'title', 'scheduled_time', 'created_at'])
->defaultSort('scheduled_time', 'asc')
```

If the client doesn't send `sort` or sends only non-whitelisted fields, the `defaultSort` is applied. If no `defaultSort` is configured, no sorting is applied.

### Search Fields

Define fields for full-text search (the `search` operator). Supports dot notation for related model fields:

```php
->searchFields(['title', 'employee.first_name', 'employee.last_name'])
```

When the client sends `"search": "John"`, the query becomes:

```sql
WHERE (title LIKE '%John%' OR EXISTS (
    SELECT * FROM employees WHERE employees.id = tasks.employee_id
    AND (first_name LIKE '%John%' OR last_name LIKE '%John%')
))
```

If `searchFields` is not configured, the `search` operator is silently ignored.

### Extending Search (searchUsing)

For searching data that cannot be expressed via `searchFields` (pivot tables, computed fields, custom fields with business logic), use `searchUsing()`:

```php
->searchUsing(function (\Illuminate\Database\Eloquent\Builder $query, string $term) {
    // Called inside the OR group alongside searchFields.
    // Use $query->orWhere / orWhereIn / orWhereHas to add conditions.
    // $term is the original search term (not escaped).
})
```

The callback is invoked inside the shared `WHERE (...)` search group, so all conditions are OR-combined with the rest of the search fields.

**Example: searching custom fields on tasks**

```php
// In the controller — company_id is available
$config = (new Task)->searchableConfig()
    ->searchUsing(function (Builder $query, string $term) use ($company) {
        $searchableFieldIds = TaskCustomField::where('company_id', $company->id)
            ->where('searchable', true)
            ->pluck('id');

        if ($searchableFieldIds->isEmpty()) {
            return;
        }

        $taskIds = TaskCustomFieldValue::whereIn('task_custom_field_id', $searchableFieldIds)
            ->where('value', 'LIKE', "%{$term}%")
            ->pluck('task_id')
            ->toArray();

        if (!empty($taskIds)) {
            $query->orWhereIn('id', $taskIds);
        }
    });

// Pass the augmented config to build()
$builder = SearchQuery::build($query, $payload, [], $config);
```

You can register multiple callbacks — all will be invoked within the same OR group:

```php
$config = (new Task)->searchableConfig()
    ->searchUsing($this->customFieldSearchCallback($company))
    ->searchUsing($this->anotherSearchCallback());
```

**When to use `searchUsing` instead of `searchFields`:**
- Searching pivot tables (custom fields, tags via intermediate table)
- Searching with business logic (e.g., only fields with the `searchable` flag)
- Searching computed values or subqueries
- When controller context is needed (`$company`, `$user`)

### Relations

Define which model relations can be filtered via the `has` operator:

```php
->relations([
    'latestLog' => ['status_id'],
    'client' => ['name', 'email', 'phone'],
    'employee' => ['id', 'first_name', 'last_name'],
])
```

Each relation maps to an array of allowed fields within that relation. Fields not in this list are ignored.

### Custom Filters

Register custom logic for fields that require non-standard queries:

```php
->filter('task_status', new TaskStatusFilter())
```

See [Custom Filters](#custom-filters-1) section for details.

## JSON Payload Format

The payload is a JSON object with these top-level keys:

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `where` | object | No | AND filter conditions |
| `or` | object | No | OR filter conditions (combined with `where` as `WHERE (where) OR (or)`) |
| `sort` | array | No | Sorting rules |
| `search` | string | No | Full-text search term (can also be inside `where`) |
| `page` | integer | No | Page number (enables pagination) |
| `per_page` | integer | No | Results per page (default: 25, max: 1000) |
| `count_only` | boolean | No | If true, returns only `{"total": N}` |

### Operators

All operators are keys inside the `where` (or `or`) object. Each operator maps field names to values:

```json
{
    "where": {
        "OPERATOR": {
            "FIELD": "VALUE"
        }
    }
}
```

#### Comparison operators

| Operator | SQL | Value type | Example |
|----------|-----|------------|---------|
| `eq` | `= value` | scalar | `{"eq": {"status": "active"}}` |
| `not_eq` | `!= value` | scalar | `{"not_eq": {"status": "cancelled"}}` |
| `gt` | `> value` | scalar | `{"gt": {"id": 100}}` |
| `gte` | `>= value` | scalar | `{"gte": {"price": 50.00}}` |
| `lt` | `< value` | scalar | `{"lt": {"id": 1000}}` |
| `lte` | `<= value` | scalar | `{"lte": {"price": 200.00}}` |

#### Set operators

| Operator | SQL | Value type | Example |
|----------|-----|------------|---------|
| `in` | `IN (...)` | non-empty array | `{"in": {"employee_id": [1, 2, 3]}}` |
| `not_in` | `NOT IN (...)` | non-empty array | `{"not_in": {"status": [5, 6]}}` |

#### Range operator

| Operator | SQL | Value type | Example |
|----------|-----|------------|---------|
| `between` | `BETWEEN a AND b` | array of 2 elements | `{"between": {"scheduled_time": ["2026-04-01", "2026-04-30"]}}` |

#### String operator

| Operator | SQL | Value type | Example |
|----------|-----|------------|---------|
| `like` | `LIKE '%value%'` | string | `{"like": {"title": "repair"}}` |

The `%` wrapping is automatic. Special characters (`%`, `_`, `!`) are auto-escaped.

#### Null operators

| Operator | SQL | Value type | Example |
|----------|-----|------------|---------|
| `is_null` | `IS NULL` / `IS NOT NULL` | boolean or array | `{"is_null": {"cancelled_at": true}}` |

**Object format:** `{"is_null": {"field": true}}` — `true` = IS NULL, `false` = IS NOT NULL.

**Array shorthand:** `{"is_null": ["field1", "field2"]}` — all listed fields must be NULL. Equivalent to `{"is_null": {"field1": true, "field2": true}}`.

#### JSON operators

| Operator | SQL | Logic | Example |
|----------|-----|-------|---------|
| `json_contains` | `JSON_CONTAINS` | ANY (OR) | `{"json_contains": {"marks": [1, 2]}}` — has mark 1 OR 2 |
| `json_contains_all` | `JSON_CONTAINS` | ALL (AND) | `{"json_contains_all": {"skills": [5, 10]}}` — has skill 5 AND 10 |

Both accept a scalar (single value) or an array (multiple values).

### Combining operators

All operators within a `where` block are combined with AND:

```json
{
    "where": {
        "eq": { "employee_id": 42 },
        "between": { "scheduled_time": ["2026-04-01", "2026-04-30"] },
        "is_null": { "cancelled_at": true }
    }
}
```

Generates:

```sql
WHERE employee_id = 42
  AND scheduled_time BETWEEN '2026-04-01' AND '2026-04-30'
  AND cancelled_at IS NULL
```

### Sorting

```json
{
    "sort": [
        { "field": "scheduled_time", "dir": "desc" },
        { "field": "id", "dir": "asc" }
    ]
}
```

- `field` (required) must be in the model's `sortable()` whitelist
- `dir` (optional) defaults to `"asc"`. Valid values: `"asc"`, `"desc"`
- Non-whitelisted fields are silently skipped
- If no valid sort provided, `defaultSort()` is used as fallback

### Pagination

```json
{
    "page": 1,
    "per_page": 50
}
```

When `page` is present, the response includes pagination metadata:

```json
{
    "data": [...],
    "total": 150,
    "page": 1,
    "per_page": 50,
    "last_page": 3
}
```

When `page` is absent, `SearchQuery::apply()` returns the same paginated format with `page: 1`. Use `SearchQuery::build()` + `->get()` for unpaginated results.

### Count Only

```json
{
    "count_only": true,
    "where": { "eq": { "employee_id": 42 } }
}
```

Response:

```json
{
    "total": 15
}
```

No data is returned. Useful for badge counters, tab counts, etc.

### Full-Text Search

Search can be a top-level key or inside `where`:

```json
{
    "where": {
        "search": "John repair",
        "between": { "scheduled_time": ["2026-04-01", "2026-04-30"] }
    }
}
```

The search term is matched against all fields defined in `searchFields()`. For related fields (dot notation like `employee.first_name`), the library uses `whereHas` automatically. To extend search beyond standard fields, use [`searchUsing()`](#extending-search-searchusing).

### OR Conditions

Use the `or` block alongside `where` for `(where) OR (or)` logic:

```json
{
    "where": {
        "eq": { "employee_id": 42 }
    },
    "or": {
        "eq": { "created_by": 42 }
    }
}
```

Generates:

```sql
WHERE (employee_id = 42) OR (created_by = 42)
```

The `or` block supports all the same operators as `where`. Nesting `or` inside `or` is not allowed.

### AND-OR Groups

For complex conditions like "employee is 42 OR client is in [10, 20]":

```json
{
    "where": {
        "and_or": [
            { "eq": { "employee_id": 42 } },
            { "in": { "client_id": [10, 20] } }
        ]
    }
}
```

Each array element is an OR group. Groups are combined with AND:

```sql
WHERE (employee_id = 42)
  AND (client_id IN (10, 20))
```

Within a single group with multiple conditions, the first is AND and the rest are OR:

```json
{
    "and_or": [
        { "eq": { "employee_id": 42, "created_by": 42 } }
    ]
}
```

```sql
WHERE (employee_id = 42 OR created_by = 42)
```

### Relation Filtering (has)

Filter records based on related model data using `has`:

```json
{
    "where": {
        "has": {
            "latestLog": {
                "in": { "status_id": [1, 2, 3] }
            },
            "client": {
                "like": { "name": "Acme" },
                "load": false
            }
        }
    }
}
```

Generates:

```sql
WHERE EXISTS (SELECT * FROM task_logs WHERE task_logs.task_id = tasks.id AND status_id IN (1, 2, 3))
  AND EXISTS (SELECT * FROM clients WHERE clients.id = tasks.client_id AND name LIKE '%Acme%')
```

Each relation key maps to an object of operators that apply to the related table's fields.

**Eager loading:** By default, filtered relations are added to `with()` for eager loading. Set `"load": false` to filter without loading the relation data.

**Whitelisting:** Relations must be defined in both:
1. The model's `->relations([...])` config (with allowed fields)
2. The controller's `$allowedRelations` array (if passed to `SearchQuery`)

## API Reference

### SearchQuery::apply()

All-in-one: validates, filters, sorts, and paginates.

```php
$result = SearchQuery::apply(
    $query,                // Eloquent Builder
    $payload,              // JSON payload (array)
    $allowedRelations      // optional: ['relation1', 'relation2']
);

// $result = ['data' => [...], 'total' => 42, 'page' => 1, 'per_page' => 25, 'last_page' => 2]
```

### SearchQuery::build()

Step-by-step: validates, filters, and sorts, but does NOT paginate. Returns a `SearchBuilder` for manual control.

```php
$builder = SearchQuery::build($query, $payload, $allowedRelations);
```

The optional fourth parameter `$config` overrides the model's config — useful for adding `searchUsing` in the controller:

```php
$config = (new Task)->searchableConfig()
    ->searchUsing($this->customFieldSearchCallback($company));

$builder = SearchQuery::build($query, $payload, [], $config);
```

### SearchBuilder

Returned by `SearchQuery::build()`. Provides access to the modified query:

```php
// Get the raw Eloquent Builder (for further modifications)
$eloquentQuery = $builder->getQuery();

// Execute and get all results (no pagination)
$collection = $builder->get();

// Get paginated results (same format as apply())
$result = $builder->paginate();

// Get count only
$count = $builder->count();
```

**Typical pattern** for unpaginated results:

```php
$builder = SearchQuery::build($query, $payload);
$tasks = $builder->get();

return response()->json($tasks);
```

**Typical pattern** for conditional pagination:

```php
$builder = SearchQuery::build($query, $payload);

if (isset($payload['page'])) {
    return response()->json($builder->paginate());
} else {
    return response()->json($builder->get());
}
```

## Operator Auto-Resolution

The library automatically determines which operators are valid for each field based on the model's `$casts` and database column types:

| Type | Auto-resolved operators |
|------|------------------------|
| `integer`, `bigint`, `smallint`, etc. | `eq`, `not_eq`, `in`, `not_in`, `gt`, `lt`, `gte`, `lte`, `between` |
| `float`, `double`, `decimal` | `eq`, `not_eq`, `in`, `not_in`, `gt`, `lt`, `gte`, `lte`, `between` |
| `string` | `eq`, `not_eq`, `like`, `in`, `not_in` |
| `boolean` | `eq`, `not_eq` |
| `datetime`, `timestamp` | `eq`, `between`, `gt`, `lt`, `gte`, `lte` |
| `date` | `eq`, `between`, `gt`, `lt`, `gte`, `lte` |
| `array`, `collection`, `json` | `json_contains`, `json_contains_all` |

Additionally:
- **Nullable columns** get `is_null` added automatically
- **`jsonFields()`** forces `json_contains` + `json_contains_all` regardless of cast type
- **Explicit overrides** (`'status' => ['eq', 'in']`) bypass auto-resolution entirely

## Custom Filters

For queries that cannot be expressed through the standard operators (subqueries, computed fields, cross-table aggregation), implement the `CustomFilter` interface:

```php
use Illuminate\Database\Eloquent\Builder;
use DartVadius\EloquentSearch\Contracts\CustomFilter;

class TaskStatusFilter implements CustomFilter
{
    public function apply(Builder $query, string $operator, mixed $value): void
    {
        // Example: filter by latest log's status via subquery
        $subquery = '(SELECT status_id FROM task_logs
                      WHERE task_id = tasks.id
                      ORDER BY id DESC LIMIT 1)';

        match ($operator) {
            'eq'     => $query->where(\DB::raw($subquery), $value),
            'in'     => $query->whereIn(\DB::raw($subquery), (array) $value),
            'not_in' => $query->whereNotIn(\DB::raw($subquery), (array) $value),
        };
    }

    public function allowedOperators(): array
    {
        return ['eq', 'in', 'not_in'];
    }
}
```

Register in the model config:

```php
public function searchableConfig(): SearchableConfig
{
    return SearchableConfig::make()
        ->fields(['id', 'title', 'scheduled_time'])
        ->filter('task_status', new TaskStatusFilter());
}
```

Use in JSON payload:

```json
{
    "where": {
        "in": { "task_status": [1, 2, 3] }
    }
}
```

**Important:** Custom filters are registered by **field name** (not operator). When a field matches a custom filter, all operator handling is delegated to that filter. The `allowedOperators()` method controls which operators the filter accepts.

## Configuration

After publishing (`php artisan vendor:publish --tag=eloquent-search-config`), edit `config/eloquent-search.php`:

```php
return [
    // Pagination defaults
    'pagination' => [
        'default_per_page' => 25,    // Default page size
        'max_per_page' => 1000,      // Maximum allowed page size (silently capped)
    ],

    // Safety limits to prevent abuse
    'limits' => [
        'max_conditions' => 50,      // Max total conditions across where + or + and_or + has
        'max_or_conditions' => 10,   // Max groups in and_or
        'max_in_values' => 500,      // Max array items in in/not_in/json_contains
    ],

    // What to do with fields not in the whitelist
    'on_unknown_field' => 'skip',    // 'skip' = silently ignore, 'throw' = throw InvalidPayloadException
];
```

## Validation & Error Handling

The library validates the payload structure before executing any queries. Invalid payloads throw `DartVadius\EloquentSearch\Exceptions\InvalidPayloadException` (extends `Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException`).

In a Laravel application, this means invalid payloads automatically return a `422 Unprocessable Entity` HTTP response — no manual `try/catch` required.

**What is validated:**

| Check | Error |
|-------|-------|
| `where` / `or` is not an object | `"where" must be an object.` |
| Nested `or` inside `or` | `Nested "or" inside "or" is not supported.` |
| Nested `has` inside `has` | `Nested "has" is not supported.` |
| Nested `and_or` inside `and_or` | `Nested "and_or" inside and_or is not supported.` |
| `eq` value is array | `eq.field in where: expected scalar, got array.` |
| `in` value is empty | `in.field in where: expected non-empty array.` |
| `between` value has != 2 elements | `between.field in where: expected array with exactly 2 elements.` |
| `like` value is not string | `like.field in where: expected string, got integer.` |
| `is_null` value is not boolean/array | `is_null.field in where: expected boolean, got string.` |
| `is_null` array shorthand has non-string | `is_null[1] in where: expected string field name, got integer.` |
| Total conditions > max | `Too many conditions: 55 (max: 50).` |
| `in` values > max | `in.field in where: too many values 600 (max: 500).` |
| `and_or` groups > max | `Too many "and_or" groups in where: 15 (max: 10).` |
| Invalid `page` | `"page" must be a positive integer.` |
| Invalid `sort[].dir` | `"sort[0].dir" must be "asc" or "desc".` |

**What is NOT validated** (silently ignored):
- Unknown field names (when `on_unknown_field` = `skip`)
- Operators not allowed for a field type (e.g., `like` on an integer)
- Unknown relation names in `has`
- Non-whitelisted sort fields
- `search` when no `searchFields` configured
- `search` with empty string or `null` value (treated as no search)

Since `InvalidPayloadException` extends `UnprocessableEntityHttpException`, Laravel handles it automatically — returning 422 with the error message. No `try/catch` needed in controllers.

If you need custom error formatting, you can still catch it explicitly:

```php
use DartVadius\EloquentSearch\Exceptions\InvalidPayloadException;

try {
    $result = SearchQuery::apply($query, $payload);
} catch (InvalidPayloadException $e) {
    return response()->json(['error' => $e->getMessage()], 422);
}
```

## Security Considerations

- **Field whitelisting is mandatory.** Only fields declared in `fields()` can be queried. There is no "allow all" mode.
- **Relation whitelisting is double-gated.** Both the model config and the controller's `$allowedRelations` must allow a relation.
- **Input limits prevent abuse.** `max_conditions`, `max_in_values`, and `max_or_conditions` protect against denial-of-service via complex queries.
- **Always apply authorization scopes before passing the query.** The library does not handle permissions. Set up your `WHERE company_id = ?` or role-based scopes on the Builder before calling `SearchQuery`.

```php
// Good: authorization first, then DSL
$query = Task::where('company_id', $user->company_id);
$result = SearchQuery::apply($query, $payload);

// Bad: no authorization scope
$result = SearchQuery::apply(Task::query(), $payload);
```
