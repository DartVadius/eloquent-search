<?php

namespace DartVadius\EloquentSearch\Tests\Unit;

use Orchestra\Testbench\TestCase;
use DartVadius\EloquentSearch\Exceptions\InvalidPayloadException;
use DartVadius\EloquentSearch\Parser\PayloadValidator;
use DartVadius\EloquentSearch\SearchServiceProvider;

class PayloadValidatorTest extends TestCase
{
    protected PayloadValidator $validator;

    protected function getPackageProviders($app): array
    {
        return [SearchServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new PayloadValidator();
    }

    public function test_valid_empty_payload(): void
    {
        $this->validator->validate([]);
        $this->assertTrue(true);
    }

    public function test_valid_where_with_eq(): void
    {
        $this->validator->validate([
            'where' => [
                'eq' => ['status' => 'active'],
            ],
        ]);
        $this->assertTrue(true);
    }

    public function test_invalid_page_type(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->validator->validate(['page' => 'abc']);
    }

    public function test_invalid_page_negative(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->validator->validate(['page' => -1]);
    }

    public function test_invalid_per_page_type(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->validator->validate(['per_page' => 'abc']);
    }

    public function test_invalid_count_only_type(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->validator->validate(['count_only' => 'yes']);
    }

    public function test_invalid_where_not_array(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->validator->validate(['where' => 'invalid']);
    }

    public function test_invalid_or_not_array(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->validator->validate(['or' => 'invalid']);
    }

    public function test_nested_or_inside_or_throws(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('Nested "or"');
        $this->validator->validate([
            'or' => [
                'or' => ['eq' => ['a' => 1]],
            ],
        ]);
    }

    public function test_eq_requires_scalar(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('expected scalar');
        $this->validator->validate([
            'where' => [
                'eq' => ['field' => [1, 2]],
            ],
        ]);
    }

    public function test_in_requires_non_empty_array(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('expected non-empty array');
        $this->validator->validate([
            'where' => [
                'in' => ['field' => []],
            ],
        ]);
    }

    public function test_between_requires_two_elements(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('exactly 2 elements');
        $this->validator->validate([
            'where' => [
                'between' => ['field' => [1]],
            ],
        ]);
    }

    public function test_like_requires_string(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('expected string');
        $this->validator->validate([
            'where' => [
                'like' => ['field' => 123],
            ],
        ]);
    }

    public function test_is_null_requires_boolean(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('expected boolean');
        $this->validator->validate([
            'where' => [
                'is_null' => ['field' => 'yes'],
            ],
        ]);
    }

    public function test_sort_requires_field_key(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('must be an object with a "field" key');
        $this->validator->validate([
            'sort' => [['dir' => 'asc']],
        ]);
    }

    public function test_sort_invalid_dir(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('must be "asc" or "desc"');
        $this->validator->validate([
            'sort' => [['field' => 'id', 'dir' => 'up']],
        ]);
    }

    public function test_valid_complex_payload(): void
    {
        $this->validator->validate([
            'page' => 1,
            'per_page' => 25,
            'count_only' => false,
            'where' => [
                'eq' => ['status' => 'active'],
                'in' => ['category' => [1, 2, 3]],
                'between' => ['date' => ['2026-01-01', '2026-12-31']],
                'like' => ['title' => 'test'],
                'is_null' => ['parent_id' => true],
                'and_or' => [
                    ['eq' => ['a' => 1, 'b' => 2]],
                ],
            ],
            'or' => [
                'eq' => ['fallback' => 'yes'],
            ],
            'sort' => [
                ['field' => 'id', 'dir' => 'desc'],
            ],
        ]);
        $this->assertTrue(true);
    }

    public function test_too_many_conditions(): void
    {
        $this->app['config']->set('eloquent-search.limits.max_conditions', 3);
        $validator = new PayloadValidator();

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('Too many conditions');

        $validator->validate([
            'where' => [
                'eq' => ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4],
            ],
        ]);
    }

    public function test_too_many_in_values(): void
    {
        $this->app['config']->set('eloquent-search.limits.max_in_values', 3);
        $validator = new PayloadValidator();

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('too many values');

        $validator->validate([
            'where' => [
                'in' => ['field' => [1, 2, 3, 4]],
            ],
        ]);
    }

    public function test_valid_has_block(): void
    {
        $this->validator->validate([
            'where' => [
                'has' => [
                    'latestLog' => [
                        'in' => ['status_id' => [1, 2]],
                    ],
                    'client' => [
                        'like' => ['name' => 'test'],
                        'load' => false,
                    ],
                ],
            ],
        ]);
        $this->assertTrue(true);
    }

    public function test_has_must_be_object(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('must be an object');
        $this->validator->validate([
            'where' => ['has' => 'invalid'],
        ]);
    }

    public function test_has_relation_must_be_object(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('must be an object');
        $this->validator->validate([
            'where' => ['has' => ['relation' => 'invalid']],
        ]);
    }

    public function test_has_nested_not_supported(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('Nested "has"');
        $this->validator->validate([
            'where' => [
                'has' => [
                    'relation' => [
                        'has' => ['nested' => ['eq' => ['x' => 1]]],
                    ],
                ],
            ],
        ]);
    }

    public function test_has_load_must_be_boolean(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('must be a boolean');
        $this->validator->validate([
            'where' => [
                'has' => [
                    'relation' => [
                        'eq' => ['field' => 'value'],
                        'load' => 'yes',
                    ],
                ],
            ],
        ]);
    }

    public function test_has_validates_operator_values(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('expected non-empty array');
        $this->validator->validate([
            'where' => [
                'has' => [
                    'relation' => [
                        'in' => ['field' => []],
                    ],
                ],
            ],
        ]);
    }

    // --- Section 7: PayloadValidator Gaps ---

    public function test_page_zero_rejected(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('"page" must be a positive integer');
        $this->validator->validate(['page' => 0]);
    }

    public function test_per_page_zero_rejected(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('"per_page" must be a positive integer');
        $this->validator->validate(['per_page' => 0]);
    }

    public function test_sort_not_array_throws(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('"sort" must be an array');
        $this->validator->validate(['sort' => 'name']);
    }

    public function test_and_or_not_array_throws(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('"and_or" in where must be an array');
        $this->validator->validate([
            'where' => ['and_or' => 'invalid'],
        ]);
    }

    public function test_and_or_group_item_not_array_throws(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('must be an object');
        $this->validator->validate([
            'where' => ['and_or' => ['not_an_object']],
        ]);
    }

    public function test_nested_and_or_inside_and_or_throws(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('Nested "and_or" inside and_or is not supported');
        $this->validator->validate([
            'where' => [
                'and_or' => [
                    ['and_or' => [['eq' => ['a' => 1]]]],
                ],
            ],
        ]);
    }

    public function test_nested_or_inside_and_or_throws(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('Nested "or" inside and_or is not supported');
        $this->validator->validate([
            'where' => [
                'and_or' => [
                    ['or' => ['eq' => ['a' => 1]]],
                ],
            ],
        ]);
    }

    public function test_too_many_and_or_groups_throws(): void
    {
        $this->app['config']->set('eloquent-search.limits.max_or_conditions', 2);
        $validator = new PayloadValidator();

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('Too many "and_or" groups');

        $validator->validate([
            'where' => [
                'and_or' => [
                    ['eq' => ['a' => 1]],
                    ['eq' => ['b' => 2]],
                    ['eq' => ['c' => 3]],
                ],
            ],
        ]);
    }

    public function test_search_must_be_string(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('"search" in where must be a string');
        $this->validator->validate([
            'where' => ['search' => 123],
        ]);
    }

    public function test_json_contains_accepts_scalar_value(): void
    {
        $this->validator->validate([
            'where' => ['json_contains' => ['tags' => 5]],
        ]);
        $this->assertTrue(true);
    }

    public function test_json_contains_rejects_empty_array(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('expected non-empty array or scalar');
        $this->validator->validate([
            'where' => ['json_contains' => ['tags' => []]],
        ]);
    }

    public function test_json_contains_rejects_null(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('expected array or scalar');
        $this->validator->validate([
            'where' => ['json_contains' => ['tags' => null]],
        ]);
    }

    public function test_json_contains_too_many_values(): void
    {
        $this->app['config']->set('eloquent-search.limits.max_in_values', 3);
        $validator = new PayloadValidator();

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('too many values');

        $validator->validate([
            'where' => ['json_contains' => ['tags' => [1, 2, 3, 4]]],
        ]);
    }

    public function test_unknown_operator_silently_accepted(): void
    {
        $this->validator->validate([
            'where' => ['unknown_op' => ['field' => 'value']],
        ]);
        $this->assertTrue(true);
    }

    public function test_eq_allows_null_as_scalar(): void
    {
        $this->validator->validate([
            'where' => ['eq' => ['field' => null]],
        ]);
        $this->assertTrue(true);
    }

    public function test_has_keys_must_be_strings(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('must be relation names (strings)');
        $this->validator->validate([
            'where' => [
                'has' => [0 => ['eq' => ['x' => 1]]],
            ],
        ]);
    }

    public function test_operator_inside_has_must_contain_object(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('must contain an object');
        $this->validator->validate([
            'where' => [
                'has' => ['relation' => ['eq' => 'not_an_object']],
            ],
        ]);
    }

    public function test_between_with_three_elements_rejected(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('exactly 2 elements');
        $this->validator->validate([
            'where' => ['between' => ['field' => [1, 2, 3]]],
        ]);
    }

    public function test_conditions_counted_across_where_and_or_blocks(): void
    {
        $this->app['config']->set('eloquent-search.limits.max_conditions', 5);
        $validator = new PayloadValidator();

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('Too many conditions: 6');

        $validator->validate([
            'where' => [
                'eq' => ['a' => 1, 'b' => 2, 'c' => 3],
            ],
            'or' => [
                'eq' => ['d' => 4, 'e' => 5, 'f' => 6],
            ],
        ]);
    }

    public function test_is_null_array_shorthand_valid(): void
    {
        $this->validator->validate([
            'where' => ['is_null' => ['scheduled_at', 'cancelled_at']],
        ]);
        $this->assertTrue(true);
    }

    public function test_is_null_array_shorthand_rejects_non_string_items(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('expected string field name');
        $this->validator->validate([
            'where' => ['is_null' => ['scheduled_at', 123]],
        ]);
    }

    public function test_in_with_string_value_rejected(): void
    {
        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('expected non-empty array');
        $this->validator->validate([
            'where' => ['in' => ['field' => 'single_value']],
        ]);
    }
}
