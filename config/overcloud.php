<?php

return [
    // When true, outbound WhatsApp sends are captured (not delivered) — used by the bot:e2e self-test.
    'dry_run' => (bool) env('OVERCLOUD_DRY_RUN', false),

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
        // Run the CLI as this non-root user (which owns the PERSISTENT, auto-refreshing ~/.claude
        // creds volume) so the OAuth token is refreshed in place and survives deploys. Prod: builder.
        'run_as' => env('CLAUDE_RUN_AS'),
        'home' => env('CLAUDE_HOME'),
        // Long-lived subscription token (`claude setup-token`, valid ~1 year, does NOT rotate). When set,
        // it is the builder's auth — independent of the operator's laptop session, surviving redeploys
        // with NO re-seed. This is the permanent fix for the recurring "builder logged out" problem.
        'oauth_token' => env('CLAUDE_CODE_OAUTH_TOKEN'),
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

    // Autonomous build + deploy of the client's site/app after payment.
    'deploy' => [
        'enabled' => (bool) env('AUTODEPLOY_ENABLED', false),
        'github_token' => env('GITHUB_TOKEN'),
        'github_owner' => env('GITHUB_OWNER', 'ProIEZRush1'),
        'coolify_url' => env('COOLIFY_API_URL', 'http://coolify:8080/api/v1'),
        'coolify_token' => env('COOLIFY_API_TOKEN'),
        'coolify_project' => env('COOLIFY_PROJECT_UUID', 'm0gc8swgwcoookso8cowwc8s'),
        'coolify_server' => env('COOLIFY_SERVER_UUID', 'nwo4k04sswwos08wckkcg84s'),
        'max_attempts' => (int) env('AUTODEPLOY_MAX_ATTEMPTS', 5),
        // Agentic build budget. A full-stack app (modules + admin + npm build + server e2e + self-repair)
        // needs well over the old 1500s — that timeout was killing the agent mid-verification.
        'build_timeout' => (int) env('BUILD_TIMEOUT', 2700),
        // Post-deploy autonomous QA: a Claude Code agent E2E-tests the LIVE app (login, connect, modules)
        // and self-heals failures before delivery. Needs SSH into the app node to read real logs.
        'verify_timeout' => (int) env('VERIFY_TIMEOUT', 2400),
        'app_node_ip' => env('APP_NODE_IP', '155.117.45.31'),
        'app_node_ssh_key_b64' => env('APP_NODE_SSH_KEY_B64'), // base64 of the Coolify private key
        // The panel's OWN Coolify app uuid — so the keepalive can write the freshly-rotated Claude creds
        // back into this app's CLAUDE_CREDS_JSON env (a stale snapshot is what logs the build agent out).
        'panel_app_uuid' => env('PANEL_APP_UUID', 'oosja8agl7hp37x5b20dk8v8'),
        'default_stack' => env('DEFAULT_STACK', 'laravel-vue'),

        // Custom domains under overcloud.us via Cloudflare (falls back to sslip.io if unset).
        'cloudflare_token' => env('CLOUDFLARE_TOKEN'),
        'cloudflare_zone' => env('CLOUDFLARE_ZONE'),
        'base_domain' => env('DEPLOY_BASE_DOMAIN', 'overcloud.us'),
        'server_ip' => env('DEPLOY_SERVER_IP', '93.127.142.243'),

        // Available stacks. `kind`: web|app. `markers`: strings the live page must
        // contain to pass E2E (apps render to canvas, so they check the bootstrap).
        'stacks' => [
            'laravel-vue' => ['repo' => 'overcloud-client-template', 'port' => '8080', 'label' => 'Laravel + Vue', 'kind' => 'web', 'markers' => []],
            'whatsapp-bot' => ['repo' => 'overcloud-bot-template', 'port' => '8080', 'label' => 'Bot de WhatsApp', 'kind' => 'app', 'markers' => ['id="app"']],
            'nextjs' => ['repo' => 'overcloud-nextjs-template', 'port' => '3000', 'label' => 'Next.js', 'kind' => 'web', 'markers' => []],
            'static' => ['repo' => 'overcloud-static-template', 'port' => '80', 'label' => 'HTML estático', 'kind' => 'web', 'markers' => []],
            'flutter' => ['repo' => 'overcloud-flutter-template', 'port' => '80', 'label' => 'Flutter Web', 'kind' => 'app', 'markers' => ['flutter_bootstrap.js', 'main.dart.js']],
        ],
    ],
];
