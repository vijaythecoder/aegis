<?php

return [
    'name' => env('AEGIS_APP_NAME', 'Aegis'),
    'version' => env('AEGIS_VERSION', '0.1.0'),
    'tagline' => 'AI under your Aegis',

    'agent' => [
        'default_provider' => env('AEGIS_DEFAULT_PROVIDER', 'anthropic'),
        'default_model' => env('AEGIS_DEFAULT_MODEL', 'claude-sonnet-4-20250514'),
        'summary_provider' => env('AEGIS_SUMMARY_PROVIDER', ''),
        'summary_model' => env('AEGIS_SUMMARY_MODEL', ''),
        'context_window' => env('AEGIS_CONTEXT_WINDOW', 200000),
        'rate_limit_window_seconds' => env('AEGIS_RATE_LIMIT_WINDOW_SECONDS', 60),
        'rate_limit_max_requests' => env('AEGIS_RATE_LIMIT_MAX_REQUESTS', 120),
        'max_steps' => env('AEGIS_MAX_STEPS', 50),
        'max_retries' => env('AEGIS_MAX_RETRIES', 3),
        'timeout' => env('AEGIS_TIMEOUT', 120),
        'project_path' => env('AEGIS_PROJECT_PATH'),
        'planning_enabled' => (bool) env('AEGIS_PLANNING_ENABLED', true),
        'reflection_enabled' => (bool) env('AEGIS_REFLECTION_ENABLED', false),
        'failover_enabled' => (bool) env('AEGIS_FAILOVER_ENABLED', true),
    ],

    'providers' => [
        'anthropic' => [
            'name' => 'Anthropic (Claude)',
            'pricing_tier' => 'premium',
            'models' => [
                'claude-sonnet-4-20250514' => [
                    'context_window' => 200000,
                    'tools' => true,
                    'vision' => true,
                    'streaming' => true,
                    'structured_output' => true,
                ],
                'claude-4-opus-20250514' => [
                    'context_window' => 200000,
                    'tools' => true,
                    'vision' => true,
                    'streaming' => true,
                    'structured_output' => true,
                ],
                'claude-3-5-haiku-20241022' => [
                    'context_window' => 200000,
                    'tools' => true,
                    'vision' => true,
                    'streaming' => true,
                    'structured_output' => true,
                ],
            ],
            'default_model' => 'claude-sonnet-4-20250514',
        ],
        'openai' => [
            'name' => 'OpenAI (GPT)',
            'pricing_tier' => 'premium',
            'models' => [
                'gpt-4o' => [
                    'context_window' => 128000,
                    'tools' => true,
                    'vision' => true,
                    'streaming' => true,
                    'structured_output' => true,
                ],
                'gpt-4.1' => [
                    'context_window' => 1047576,
                    'tools' => true,
                    'vision' => true,
                    'streaming' => true,
                    'structured_output' => true,
                ],
                'gpt-4o-mini' => [
                    'context_window' => 128000,
                    'tools' => true,
                    'vision' => true,
                    'streaming' => true,
                    'structured_output' => true,
                ],
                'o3' => [
                    'context_window' => 200000,
                    'tools' => true,
                    'vision' => true,
                    'streaming' => true,
                    'structured_output' => true,
                ],
            ],
            'default_model' => 'gpt-4o',
        ],
        'gemini' => [
            'name' => 'Google (Gemini)',
            'pricing_tier' => 'premium',
            'models' => [
                'gemini-2.5-pro' => [
                    'context_window' => 1048576,
                    'tools' => true,
                    'vision' => true,
                    'streaming' => true,
                    'structured_output' => true,
                ],
                'gemini-2.5-flash' => [
                    'context_window' => 1048576,
                    'tools' => true,
                    'vision' => true,
                    'streaming' => true,
                    'structured_output' => true,
                ],
            ],
            'default_model' => 'gemini-2.5-pro',
        ],
        'deepseek' => [
            'name' => 'DeepSeek',
            'pricing_tier' => 'value',
            'models' => [
                'deepseek-chat' => [
                    'context_window' => 64000,
                    'tools' => true,
                    'vision' => false,
                    'streaming' => true,
                    'structured_output' => true,
                ],
                'deepseek-reasoner' => [
                    'context_window' => 64000,
                    'tools' => false,
                    'vision' => false,
                    'streaming' => true,
                    'structured_output' => false,
                ],
            ],
            'default_model' => 'deepseek-chat',
        ],
        'groq' => [
            'name' => 'Groq',
            'pricing_tier' => 'fast',
            'models' => [
                'llama-3.3-70b-versatile' => [
                    'context_window' => 128000,
                    'tools' => true,
                    'vision' => false,
                    'streaming' => true,
                    'structured_output' => true,
                ],
                'llama-3.1-8b-instant' => [
                    'context_window' => 128000,
                    'tools' => true,
                    'vision' => false,
                    'streaming' => true,
                    'structured_output' => true,
                ],
            ],
            'default_model' => 'llama-3.3-70b-versatile',
        ],
        'openrouter' => [
            'name' => 'OpenRouter',
            'pricing_tier' => 'flexible',
            'models' => [],
            'default_model' => env('AEGIS_OPENROUTER_DEFAULT_MODEL', 'anthropic/claude-sonnet-4'),
        ],
        'xai' => [
            'name' => 'xAI (Grok)',
            'pricing_tier' => 'premium',
            'models' => [
                'grok-3' => [
                    'context_window' => 131072,
                    'tools' => true,
                    'vision' => true,
                    'streaming' => true,
                    'structured_output' => true,
                ],
                'grok-3-mini' => [
                    'context_window' => 131072,
                    'tools' => true,
                    'vision' => false,
                    'streaming' => true,
                    'structured_output' => true,
                ],
            ],
            'default_model' => 'grok-3',
        ],
        'mistral' => [
            'name' => 'Mistral',
            'pricing_tier' => 'value',
            'models' => [
                'mistral-large-latest' => [
                    'context_window' => 128000,
                    'tools' => true,
                    'vision' => false,
                    'streaming' => true,
                    'structured_output' => true,
                ],
                'mistral-small-latest' => [
                    'context_window' => 128000,
                    'tools' => true,
                    'vision' => false,
                    'streaming' => true,
                    'structured_output' => true,
                ],
            ],
            'default_model' => 'mistral-large-latest',
        ],
        'ollama' => [
            'name' => 'Ollama (Local)',
            'pricing_tier' => 'local',
            'models' => [],
            'default_model' => null,
            'base_url' => env('AEGIS_OLLAMA_BASE_URL', 'http://localhost:11434'),
        ],
    ],

    'failover_chain' => ['anthropic', 'openai', 'gemini'],

    'delegation' => [
        'max_depth' => (int) env('AEGIS_DELEGATION_MAX_DEPTH', 3),
        'circular_check' => (bool) env('AEGIS_DELEGATION_CIRCULAR_CHECK', true),
    ],

    'security' => [
        'auto_allow_read' => true,
        'approval_timeout' => 60,
        'sandbox_mode' => env('AEGIS_SANDBOX_MODE', 'auto'),
        'blocked_commands' => [
            'rm -rf /',
            'mkfs',
            'dd if=',
            ':(){ :|:& };:',
            'chmod -R 777 /',
        ],
        'allowed_paths' => [
            base_path(),
            storage_path(),
        ],
    ],

    'browser' => [
        'enabled' => true,
        'max_tabs' => 5,
        'timeout' => 30,
        'screenshot_path' => storage_path('app/screenshots'),
        'blocked_schemes' => ['file://', 'chrome://', 'about:', 'javascript:', 'data:'],
        'blocked_hosts' => ['localhost', '127.0.0.1', '0.0.0.0'],
        'allow_localhost' => false,
        'headless' => true,
    ],

    'memory' => [
        'max_conversation_messages' => 100,
        'fts_enabled' => true,
        'fact_extraction' => true,
        'embedding_provider' => env('AEGIS_EMBEDDING_PROVIDER', 'openai'),
        'embedding_model' => env('AEGIS_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'embedding_dimensions' => (int) env('AEGIS_EMBEDDING_DIMENSIONS', 1536),
        'hybrid_search_alpha' => (float) env('AEGIS_HYBRID_SEARCH_ALPHA', 0.7),
        'auto_recall' => (bool) env('AEGIS_MEMORY_AUTO_RECALL', true),
        'reranking_enabled' => (bool) env('AEGIS_RERANKING_ENABLED', false),
    ],

    'rag' => [
        'chunk_size' => (int) env('AEGIS_RAG_CHUNK_SIZE', 512),
        'chunk_overlap' => (int) env('AEGIS_RAG_CHUNK_OVERLAP', 50),
        'max_file_size_mb' => (float) env('AEGIS_RAG_MAX_FILE_SIZE_MB', 10),
        'max_retrieval_results' => (int) env('AEGIS_RAG_MAX_RETRIEVAL_RESULTS', 10),
        'auto_retrieve' => (bool) env('AEGIS_RAG_AUTO_RETRIEVE', true),
    ],

    'context' => [
        'system_prompt_budget' => 0.15,
        'memories_budget' => 0.10,
        'summary_budget' => 0.10,
        'messages_budget' => 0.60,
        'response_reserve' => 0.05,
    ],

    'messaging' => [
        'enabled' => false,
        'rate_limit_window_seconds' => env('AEGIS_MESSAGING_RATE_LIMIT_WINDOW_SECONDS', 60),
        'rate_limit_max_requests' => env('AEGIS_MESSAGING_RATE_LIMIT_MAX_REQUESTS', 60),
        'discord' => [
            'bot_token' => env('AEGIS_DISCORD_BOT_TOKEN'),
            'application_id' => env('AEGIS_DISCORD_APPLICATION_ID'),
            'public_key' => env('AEGIS_DISCORD_PUBLIC_KEY'),
        ],
        'telegram' => [
            'bot_token' => env('AEGIS_TELEGRAM_BOT_TOKEN'),
            'webhook_url' => env('AEGIS_TELEGRAM_WEBHOOK_URL'),
            'mode' => env('AEGIS_TELEGRAM_MODE', 'webhook'),
        ],
        'whatsapp' => [
            'phone_number_id' => env('AEGIS_WHATSAPP_PHONE_NUMBER_ID'),
            'access_token' => env('AEGIS_WHATSAPP_ACCESS_TOKEN'),
            'verify_token' => env('AEGIS_WHATSAPP_VERIFY_TOKEN'),
            'app_secret' => env('AEGIS_WHATSAPP_APP_SECRET'),
        ],
        'slack' => [
            'bot_token' => env('AEGIS_SLACK_BOT_TOKEN'),
            'signing_secret' => env('AEGIS_SLACK_SIGNING_SECRET'),
            'app_token' => env('AEGIS_SLACK_APP_TOKEN'),
        ],
        'imessage' => [
            'enabled' => env('AEGIS_IMESSAGE_ENABLED', PHP_OS === 'Darwin'),
            'poll_interval_seconds' => env('AEGIS_IMESSAGE_POLL_INTERVAL', 5),
        ],
        'signal' => [
            'enabled' => env('AEGIS_SIGNAL_ENABLED', false),
            'signal_cli_path' => env('AEGIS_SIGNAL_CLI_PATH', 'signal-cli'),
            'phone_number' => env('AEGIS_SIGNAL_PHONE_NUMBER'),
            'poll_interval_seconds' => env('AEGIS_SIGNAL_POLL_INTERVAL', 5),
        ],
        'adapters' => [
            'telegram' => App\Messaging\Adapters\TelegramAdapter::class,
            'discord' => App\Messaging\Adapters\DiscordAdapter::class,
            'whatsapp' => App\Messaging\Adapters\WhatsAppAdapter::class,
            'slack' => App\Messaging\Adapters\SlackAdapter::class,
            'imessage' => App\Messaging\Adapters\IMessageAdapter::class,
            'signal' => App\Messaging\Adapters\SignalAdapter::class,
        ],
    ],

    'pricing' => [
        'api_url' => env('AEGIS_PRICING_API_URL', 'https://models.dev/api.json'),
        'cache_ttl_hours' => (int) env('AEGIS_PRICING_CACHE_TTL_HOURS', 24),
    ],

    'openrouter' => [
        'models_api_url' => env('AEGIS_OPENROUTER_MODELS_URL', 'https://openrouter.ai/api/v1/models'),
        'cache_ttl_hours' => (int) env('AEGIS_OPENROUTER_CACHE_TTL_HOURS', 24),
    ],

    'ui' => [
        'theme' => 'dark',
        'sidebar_width' => 280,
    ],

    'plugins' => [
        'enabled' => true,
        'path' => base_path('plugins'),
        'auto_discover' => true,
        'enabled_plugins' => [],
        'sandbox_mode' => env('AEGIS_PLUGIN_SANDBOX_MODE', 'process'),
        'sandbox' => [
            'timeout' => env('AEGIS_PLUGIN_SANDBOX_TIMEOUT', 30),
            'memory_limit_mb' => env('AEGIS_PLUGIN_SANDBOX_MEMORY_LIMIT_MB', 128),
            'temp_path' => storage_path('app/plugins/sandbox'),
            'docker' => [
                'image' => env('AEGIS_PLUGIN_SANDBOX_DOCKER_IMAGE', 'php:8.2-cli'),
            ],
        ],
    ],

    'marketplace' => [
        'enabled' => true,
        'registry_url' => env('AEGIS_MARKETPLACE_URL', 'https://marketplace.aegis.dev/api'),
        'cache_ttl' => 3600,
    ],

    'mcp' => [
        'enabled' => true,
        'auth_method' => env('AEGIS_MCP_AUTH_METHOD', 'sanctum'),
        'allowed_tools' => ['*'],
    ],
];
