# Draft: Aegis Power-Up — Surpassing OpenClaw

## Requirements (confirmed)
- Make Aegis more powerful than OpenClaw with unlimited memory, RAG, better tools, security
- Use Laravel AI SDK (`laravel/ai`) capabilities
- SQLite vector storage for local-first approach
- Cross-conversation recall ("unlimited memories")
- Knowledge base with document ingestion
- More tools, better agent loop
- Security as #1 differentiator ("AI under your Aegis")
- User confirmed: "all other stuff what you said is true"

## Research Findings

### OpenClaw Analysis (67.8k stars, $18.8M funding)
- Python + FastAPI + LiteLLM + Docker sandbox
- Does NOT use vector embeddings — keyword-triggered skills + LLM summarization
- Memory = EventStream + condensation strategies
- Skills = markdown files with YAML frontmatter
- Security = Docker container per session + SecurityAnalyzer
- 77.6% SWE-Bench benchmark
- Multiple interfaces: Web GUI, CLI, SDK, VS Code, Chrome ext

### Laravel AI SDK (laravel/ai) — SEPARATE from Prism PHP
- Official first-party, released Feb 5 2026
- RemembersConversations, SimilaritySearch, FileSearch, WebSearch
- Embeddings generation, Agent loops, Broadcasting, Queueing
- Deep Laravel integration

### Current Aegis State
- Using Prism PHP (community), NOT Laravel AI SDK
- Memory: FTS5 keyword only, regex fact extraction, no vectors
- Agent Loop: Working (multi-step, streaming, failover)
- Tools: 6 working (file R/W/list, shell, browser, web_search[stubbed])
- Security: Permission system + audit logs (no Docker sandbox)
- DB: conversations, messages, memories, tool_permissions, audit_logs

### Memory Architecture Best Practices
- Four-tier: Working → Short-term → Long-term → Semantic
- sqlite-vec for local vectors (6.7k stars, replaces sqlite-vss)
- nomic-embed-text via Ollama for local embeddings
- Hybrid search (vector + BM25) = 15-30% better
- Tree-sitter for code chunking
- Incremental indexing

## Technical Decisions
- Migrate from Prism PHP → Laravel AI SDK (pending user confirmation)
- sqlite-vec for vector storage (pending NativePHP compatibility check)
- Ollama for local embeddings (pending user preference)
- TDD with Pest (established convention)

## Scope Boundaries
- INCLUDE: Memory system, RAG, tools, security, agent improvements
- EXCLUDE: Visual workflow builder (Phase 5 — future), multi-user (future)

## Decisions Made
1. **Full migration** from Prism PHP → Laravel AI SDK (laravel/ai)
2. **Both** embedding options — user chooses in Settings: local (Ollama) or cloud (OpenAI/Anthropic)
3. **Full vision** — all 5 phases, one comprehensive plan
4. TDD with Pest (established convention)

## Open Questions (resolved or deferred)
- sqlite-vec: NativePHP compatibility — explore agent will verify during implementation
- Docker sandbox: include as optional for desktop — user can enable if Docker available
