<?php

namespace DartVadius\EloquentSearch\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Orchestra\Testbench\TestCase;
use DartVadius\EloquentSearch\Searchable;
use DartVadius\EloquentSearch\SearchableConfig;
use DartVadius\EloquentSearch\SearchQuery;
use DartVadius\EloquentSearch\SearchServiceProvider;

class HasFilterTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [SearchServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__ . '/../migrations');
        $this->seedData();
    }

    protected function seedData(): void
    {
        $cat1 = HasTestCategory::create(['name' => 'Plumbing', 'type' => 'service']);
        $cat2 = HasTestCategory::create(['name' => 'Electrical', 'type' => 'service']);
        $cat3 = HasTestCategory::create(['name' => 'General', 'type' => 'general']);

        $t1 = HasTestItem::create(['name' => 'Fix pipe', 'status' => 'active', 'category_id' => $cat1->id]);
        $t2 = HasTestItem::create(['name' => 'Wire outlet', 'status' => 'active', 'category_id' => $cat2->id]);
        $t3 = HasTestItem::create(['name' => 'Clean office', 'status' => 'inactive', 'category_id' => $cat3->id]);
        $t4 = HasTestItem::create(['name' => 'Replace faucet', 'status' => 'active', 'category_id' => $cat1->id]);

        // Logs: t1 has latest status 2, t2 has latest status 3, t3 has latest status 1, t4 has latest status 2
        HasTestLog::create(['test_model_id' => $t1->id, 'status_id' => 1, 'message' => 'created']);
        HasTestLog::create(['test_model_id' => $t1->id, 'status_id' => 2, 'message' => 'in progress']);

        HasTestLog::create(['test_model_id' => $t2->id, 'status_id' => 1, 'message' => 'created']);
        HasTestLog::create(['test_model_id' => $t2->id, 'status_id' => 2, 'message' => 'in progress']);
        HasTestLog::create(['test_model_id' => $t2->id, 'status_id' => 3, 'message' => 'completed']);

        HasTestLog::create(['test_model_id' => $t3->id, 'status_id' => 1, 'message' => 'created']);

        HasTestLog::create(['test_model_id' => $t4->id, 'status_id' => 1, 'message' => 'created']);
        HasTestLog::create(['test_model_id' => $t4->id, 'status_id' => 2, 'message' => 'in progress']);
    }

    public function test_has_with_eq_filters_by_relation(): void
    {
        $result = SearchQuery::apply(HasTestItem::query(), [
            'where' => [
                'has' => [
                    'category' => [
                        'eq' => ['name' => 'Plumbing'],
                    ],
                ],
            ],
        ], ['category', 'latestLog', 'logs']);

        $this->assertEquals(2, $result['total']);
        $names = collect($result['data'])->pluck('name')->sort()->values()->all();
        $this->assertEquals(['Fix pipe', 'Replace faucet'], $names);
    }

    public function test_has_with_in_on_latest_log(): void
    {
        $result = SearchQuery::apply(HasTestItem::query(), [
            'where' => [
                'has' => [
                    'latestLog' => [
                        'in' => ['status_id' => [2]],
                    ],
                ],
            ],
        ], ['category', 'latestLog', 'logs']);

        // t1 (latest status 2), t4 (latest status 2) — t2 latest is 3, t3 latest is 1
        $this->assertEquals(2, $result['total']);
    }

    public function test_has_with_like_on_relation(): void
    {
        $result = SearchQuery::apply(HasTestItem::query(), [
            'where' => [
                'has' => [
                    'category' => [
                        'like' => ['name' => 'Electr'],
                    ],
                ],
            ],
        ], ['category', 'latestLog']);

        $this->assertEquals(1, $result['total']);
        $this->assertEquals('Wire outlet', $result['data'][0]->name);
    }

    public function test_has_auto_loads_relation(): void
    {
        $result = SearchQuery::apply(HasTestItem::query(), [
            'where' => [
                'has' => [
                    'category' => [
                        'eq' => ['name' => 'Plumbing'],
                    ],
                ],
            ],
        ], ['category']);

        // Category should be eager-loaded
        $this->assertTrue($result['data'][0]->relationLoaded('category'));
        $this->assertEquals('Plumbing', $result['data'][0]->category->name);
    }

    public function test_has_load_false_does_not_eager_load(): void
    {
        $result = SearchQuery::apply(HasTestItem::query(), [
            'where' => [
                'has' => [
                    'category' => [
                        'eq' => ['name' => 'Plumbing'],
                        'load' => false,
                    ],
                ],
            ],
        ], ['category']);

        // Category should NOT be loaded
        $this->assertFalse($result['data'][0]->relationLoaded('category'));
        // But filter should still work
        $this->assertEquals(2, $result['total']);
    }

    public function test_has_skips_relation_not_in_allowed_list(): void
    {
        // Only 'latestLog' allowed, but requesting 'category'
        $result = SearchQuery::apply(HasTestItem::query(), [
            'where' => [
                'has' => [
                    'category' => [
                        'eq' => ['name' => 'Plumbing'],
                    ],
                ],
            ],
        ], ['latestLog']);

        // category filter skipped — all 4 returned
        $this->assertEquals(4, $result['total']);
    }

    public function test_has_skips_field_not_in_relation_whitelist(): void
    {
        // 'type' is NOT in category's allowed fields ['name']
        $result = SearchQuery::apply(HasTestItem::query(), [
            'where' => [
                'has' => [
                    'category' => [
                        'eq' => ['type' => 'service'],
                    ],
                ],
            ],
        ], ['category']);

        // Field skipped inside whereHas — but whereHas still runs with no conditions
        // This means all items WITH a category match
        $this->assertEquals(4, $result['total']);
    }

    public function test_has_combined_with_regular_filters(): void
    {
        $result = SearchQuery::apply(HasTestItem::query(), [
            'where' => [
                'eq' => ['status' => 'active'],
                'has' => [
                    'latestLog' => [
                        'in' => ['status_id' => [2]],
                    ],
                ],
            ],
        ], ['latestLog']);

        // active AND latestLog status 2: t1 (active, status 2), t4 (active, status 2)
        $this->assertEquals(2, $result['total']);
    }

    public function test_has_skips_relation_not_in_config(): void
    {
        // 'logs' is in allowedRelations but NOT in model's ->relations() config
        $result = SearchQuery::apply(HasTestItem::query(), [
            'where' => [
                'has' => [
                    'logs' => [
                        'eq' => ['status_id' => 1],
                    ],
                ],
            ],
        ], ['logs']);

        // Skipped — returns all 4
        $this->assertEquals(4, $result['total']);
    }

    public function test_has_with_empty_allowed_relations_allows_all(): void
    {
        // No allowedRelations = no restriction
        $result = SearchQuery::apply(HasTestItem::query(), [
            'where' => [
                'has' => [
                    'category' => [
                        'eq' => ['name' => 'Plumbing'],
                    ],
                ],
            ],
        ]);

        $this->assertEquals(2, $result['total']);
    }

    public function test_has_in_or_block(): void
    {
        $result = SearchQuery::apply(HasTestItem::query(), [
            'where' => [
                'eq' => ['status' => 'inactive'],
            ],
            'or' => [
                'has' => [
                    'category' => [
                        'eq' => ['name' => 'Plumbing'],
                    ],
                ],
            ],
        ], ['category']);

        // inactive: Clean office (1) OR category=Plumbing: Fix pipe, Replace faucet (2) = 3
        $this->assertEquals(3, $result['total']);
    }
}

class HasTestItem extends Model
{
    use Searchable;

    protected $table = 'test_models';
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'scheduled_at' => 'datetime',
        'tags' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(HasTestCategory::class, 'category_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(HasTestLog::class, 'test_model_id');
    }

    public function latestLog(): HasOne
    {
        return $this->hasOne(HasTestLog::class, 'test_model_id')->latestOfMany();
    }

    public function searchableConfig(): SearchableConfig
    {
        return SearchableConfig::make()
            ->fields(['id', 'name', 'status', 'category_id', 'is_active'])
            ->relations([
                'category' => ['name'],
                'latestLog' => ['status_id', 'message'],
            ])
            ->sortable(['id', 'name'])
            ->defaultSort('name', 'asc');
    }
}

class HasTestCategory extends Model
{
    protected $table = 'test_categories';
    protected $guarded = [];
}

class HasTestLog extends Model
{
    protected $table = 'test_logs';
    protected $guarded = [];
}
