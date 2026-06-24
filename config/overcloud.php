<?php

return [
    // Node Baileys gateway (multi-number WhatsApp bridge)
    'gateway' => [
        'url' => env('WA_GATEWAY_URL', 'http://127.0.0.1:8088'),
        'token' => env('WA_GATEWAY_TOKEN', 'change-me'),
    ],

    // AI agent (Anthropic / Claude)
    'ai' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('AI_MODEL', 'claude-opus-4-8'),
        'enabled' => (bool) env('AI_ENABLED', true),
        'max_tokens' => (int) env('AI_MAX_TOKENS', 1500),
    ],

    'company' => [
        'name' => env('COMPANY_NAME', 'Overcloud'),
    ],
];
