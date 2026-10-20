<?php

return [
    'providers' => [
        'openai' => [
            'label' => 'OpenAI',
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        ],
        'anthropic' => [
            'label' => 'Claude',
            'model' => env('ANTHROPIC_MODEL', 'claude-3-5-haiku-latest'),
        ],
    ],
];
