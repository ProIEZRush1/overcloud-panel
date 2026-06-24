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
        // Transcribing WhatsApp voice notes. Prefer a FREE local Whisper server
        // (OpenAI-compatible API, no per-use cost); OpenAI key is only a fallback.
        'whisper_url' => env('WHISPER_URL'),
        'openai_key' => env('OPENAI_API_KEY'),
        'transcribe_model' => env('TRANSCRIBE_MODEL', 'whisper-1'),
    ],

    'company' => [
        'name' => env('COMPANY_NAME', 'Overcloud'),
        // Owner/admin phone added to every project group (digits only, country code, no +).
        'owner_phone' => env('OWNER_PHONE', '5215529535225'),
    ],

    // Dev-Business hub integration (push clients/projects/payments).
    'devbusiness' => [
        'url' => env('DEVBUSINESS_URL'),
        'token' => env('DEVBUSINESS_API_TOKEN'),
        'enabled' => (bool) env('DEVBUSINESS_SYNC', true),
    ],
];
