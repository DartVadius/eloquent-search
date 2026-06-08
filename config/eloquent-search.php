<?php

return [
    'pagination' => [
        'default_per_page' => 25,
        'max_per_page' => 1000,
    ],
    'limits' => [
        'max_conditions' => 50,
        'max_or_conditions' => 10,
        'max_in_values' => 500,
        // Aggregation: max GROUP BY levels per request, and a hard ceiling on the number of
        // grouped rows fetched (defends against runaway cardinality before PHP post-processing).
        'max_group_by_depth' => 3,
        'max_groups' => 5000,
    ],
    'on_unknown_field' => 'skip', // 'skip' | 'throw'
];
