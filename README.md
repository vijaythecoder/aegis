# Aegis — AI Under Your Aegis

Security-first, open-source AI agent platform. Desktop app with messaging integrations, plugin marketplace, and MCP server.

## Quick Start

```bash
git clone https://github.com/your-org/aegis.git
cd aegis
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
php artisan native:serve
```

## Features

- **Desktop App**: NativePHP (Electron) with dark mode, sidebar, chat UI
- **Multi-Provider LLM**: Anthropic, OpenAI, Google Gemini, DeepSeek, Groq, Ollama (local)
- **Tool System**: File, Shell, Browser, Web Search with permission approval
- **6 Messaging Platforms**: Telegram, Discord, WhatsApp, Slack, iMessage (macOS), Signal (experimental)
- **Plugin Marketplace**: Install, sign, verify, sandbox plugins
- **MCP Server**: Expose tools to external AI clients
- **Mobile Companion**: QR-code pairing, responsive chat UI
- **Auto-Update**: GitHub Releases integration with stable/beta channels

## Architecture

```
app/
├── Agent/          # AgentOrchestrator, ProviderManager, ContextManager
├── Desktop/        # NativePHP bridge, UpdateService
├── Messaging/      # 6 adapters, MessageRouter, SessionBridge
├── Mobile/         # MobilePairingService
├── Plugins/        # PluginManager, Signer, Verifier, Sandbox
├── Marketplace/    # MarketplaceService, PluginRegistry
├── Mcp/            # MCP server, tool/resource/prompt providers
├── Security/       # PermissionManager, AuditLogger, ApiKeyManager
├── Tools/          # ToolRegistry, FileTool, ShellTool, BrowserTool
└── Livewire/       # Chat, Settings, Onboarding UI components
```

## Configuration

All config in `config/aegis.php`. API keys set via:

```bash
php artisan aegis:key:set anthropic
php artisan aegis:key:set openai
```

## Testing

```bash
php artisan test              # Run all tests
php artisan test --parallel   # Parallel execution
```

## Stack

- Laravel 12 + PHP 8.2+
- NativePHP (Electron) for desktop
- Prism PHP for LLM integration
- Livewire 3 + Alpine.js + Tailwind CSS 4
- SQLite with FTS5 full-text search
- Pest PHP for testing

## License

MIT
