# Changelog

## [0.1.0] - 2026-02-13

### Added
- Desktop app via NativePHP (Electron) with dark mode UI
- Multi-provider LLM support: Anthropic, OpenAI, Gemini, DeepSeek, Groq, Ollama
- Agent runtime with tool execution loop, context management, streaming
- Tool system: File, Shell, Browser, Web Search
- Permission and approval system with audit logging
- API key management with encryption at rest
- Memory service with FTS5 full-text search
- Chat UI with Livewire 3 + Alpine.js
- Settings UI with provider configuration
- Onboarding wizard for first-time setup
- Telegram messaging adapter
- Discord messaging adapter
- WhatsApp Business API adapter
- Slack messaging adapter
- iMessage adapter (macOS-only, AppleScript bridge)
- Signal adapter (experimental, signal-cli bridge)
- Plugin system with manifest validation and auto-discovery
- Plugin marketplace with trust tiers
- Plugin signing (Ed25519) and verification
- Plugin sandboxed execution (process isolation)
- MCP server for external AI client integration
- Mobile companion with QR-code pairing
- One-click installer packaging (macOS, Windows, Linux)
- Auto-update system via GitHub Releases
- 367+ automated tests with Pest PHP
