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
    ],
    'on_unknown_field' => 'skip', // 'skip' | 'throw'
];
