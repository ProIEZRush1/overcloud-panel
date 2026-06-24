<?php

return [
    // Node Baileys gateway (multi-number WhatsApp bridge)
    'gateway' => [
        'url' => env('WA_GATEWAY_URL', 'http://127.0.0.1:8088'),
        'token' => env('WA_GATEWAY_TOKEN', 'change-me'),
    ],

    // AI assistant.
    //   driver 'claude_code' → local Claude Code CLI (uses your subscription, NO API key)
    //   driver 'api'         → Anthropic API (needs ANTHROPIC_API_KEY)
    'ai' => [
        'driver' => env('AI_DRIVER', 'claude_code'),
        'enabled' => (bool) env('AI_ENABLED', true),
        'bin' => env('CLAUDE_BIN', 'claude'),
        'model' => env('AI_MODEL', 'sonnet'),
        'timeout' => (int) env('AI_TIMEOUT', 90),
        'key' => env('ANTHROPIC_API_KEY'),
        'max_tokens' => (int) env('AI_MAX_TOKENS', 1500),
    ],

    'company' => [
        'name' => env('COMPANY_NAME', 'Overcloud'),
    ],
];
