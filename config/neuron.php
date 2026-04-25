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
        'ollama' => [
            'title' => [
                'url' => 'http://127.0.0.1:11434/api',
                'model' => 'qwen3.5:0.8b',
                'parameters' => [
                    'temperature' => 0.2,
                ],
            ],
        ],
    ],
];
