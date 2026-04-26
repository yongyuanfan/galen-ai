<?php

return [
    'models' => [
        'deepseek' => [
            'key' => 'sk-e6481f5d374f4b8e91651ea5175c7c1b',
            'model' => 'deepseek-v4-pro',
            'chat_parameters' => [
                'thinking' => [
                    'type' => 'disabled',
                ],
            ],
            'deep_thinking_parameters' => [
                'thinking' => [
                    'type' => 'enabled',
                    'reasoning_effort' => 'max',
                ],
            ],
        ],
        'embedding' => [
            'url' => 'http://localhost:11434/api',
            'model' => 'qwen3-embedding:0.6b',
        ],
    ],
];
