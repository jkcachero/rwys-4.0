<?php

return [
    'inference' => [
        'max_tokens' => 512,
        'reasoning_max_tokens' => 4096,
        'history_max_messages' => 20,
        'history_max_characters' => 24000,
    ],
    'models' => [
        // Exact text/Responses policy, reviewed against the official model pages.
        'openai' => [
            'gpt-4o-mini' => ['displayName' => 'GPT-4o mini'],
            'gpt-4.1-mini' => ['displayName' => 'GPT-4.1 mini'],
            'gpt-4.1' => ['displayName' => 'GPT-4.1'],
            'gpt-6.1-sol' => ['displayName' => 'GPT-6.1 Sol'],
        ],
    ],
    'oauth' => [
        'client_id' => env('OPENAI_OAUTH_CLIENT_ID', 'dynamic_agent_client'),
        'agent_name_hint' => env('OPENAI_OAUTH_AGENT_NAME', 'RWYS'),
        'ext_agent_host_id' => null,
        'redirect_uri' => env('OPENAI_OAUTH_REDIRECT_URI', 'http://127.0.0.1:1455/auth/callback'),
        'authorization_endpoint' => 'https://auth.openai.com/api/accounts/authorize',
        'token_endpoint' => 'https://auth.openai.com/api/accounts/oauth/token',
        'resource' => 'https://api.openai.com/v1',
        'scopes' => ['openid', 'profile', 'email', 'offline_access', 'resource.invoke', 'chatgpt.tokens.use.direct'],
        'discovery_endpoint' => 'https://auth.openai.com/.well-known/openid-configuration',
        'models_endpoint' => 'https://api.openai.com/v1/models',
        'issuer' => env('OPENAI_OAUTH_ISSUER', 'https://auth.openai.com'),
        'jwks_uri' => env('OPENAI_OAUTH_JWKS_URI', 'https://auth.openai.com/.well-known/jwks.json'),
    ],
    'providers' => [
        'openai' => [
            'label' => 'OpenAI',
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            'authentication' => ['oauth'],
            'capabilities' => ['inference', 'model_discovery'],
        ],
        'anthropic' => [
            'label' => 'Claude',
            'model' => env('ANTHROPIC_MODEL', 'claude-3-5-haiku-latest'),
            'authentication' => [],
            'capabilities' => ['inference'],
            'message' => 'Claude API access requires an API key; consumer-subscription OAuth is not supported here.',
        ],
    ],
];
