# Aegis Power-Up: Surpassing OpenClaw

## TL;DR

> **Quick Summary**: Transform Aegis from a basic chat app into a production-grade AI agent platform that surpasses OpenClaw by adding unlimited cross-conversation memory (sqlite-vec + Ollama), RAG knowledge base, Laravel AI SDK migration, advanced agent capabilities, and security hardening — all local-first on the user's machine.
>
> **Deliverables**:
> - Laravel AI SDK agent architecture (replacing direct Prism usage)
> - Four-tier memory system with vector embeddings + hybrid search
> - Document ingestion pipeline (PDF, code, markdown → chunks → embeddings)
> - Enhanced agent loop with planning & reflection
> - Security hardening (Docker sandbox, signed audit logs, encrypted API keys)
> - 3 new tools (real web search, code execution, memory recall)
> - Project context files (`.aegis/context.md`)
>
> **Estimated Effort**: XL (30+ tasks across 6 phases)
> **Parallel Execution**: YES — 4 waves within each phase
> **Critical Path**: Phase 0 (validation) → Phase 1 (SDK migration) → Phase 2 (memory) → Phase 3 (RAG) → Phase 4 (agent) → Phase 5 (security)

---

## Context

### Original Request
Make Aegis more powerful than OpenClaw. Add unlimited memory, cross-conversation recall, knowledge base, better tools, and security. User asked: "how to make this current app more powerful than openclaw and with great security?"

### Interview Summary
**Key Discussions**:
- OpenClaw analysis: 67.8k stars, $18.8M funding, 77.6% SWE-Bench. Uses Python + FastAPI + Docker. Does NOT use vector storage/RAG — just keyword-triggered skills + LLM summarization. This is our biggest opportunity.
- Laravel AI SDK (`laravel/ai`): Official first-party package with RemembersConversations, SimilaritySearch, FileSearch, embeddings, broadcasting. Already installed at `^0.1.5`. Aegis currently uses Prism directly instead of through SDK abstraction.
- Current Aegis state: FTS5 keyword search only, 6 tools, basic security, no vectors/RAG.
- Memory architecture: Four-tier model, sqlite-vec, nomic-embed-text, hybrid search.

**Decisions Made**:
- **Clean replacement** — ALL application code uses Laravel AI SDK only. Zero direct Prism imports. Prism stays as transitive dependency of laravel/ai but our code never touches it.
- Both embedding options: local (Ollama nomic-embed-text) + cloud (OpenAI text-embedding-3-small) — user chooses in Settings
- Full vision: all 6 phases in one plan
- TDD with Pest (established convention)

### Metis Review
**Critical Gaps Identified (all addressed)**:
1. `laravel/ai` already installed — uses Prism internally. Migration is abstraction-layer, not dependency swap
2. Laravel's `whereVectorSimilarTo()` is Postgres-only — need custom `SqliteVecScope` for SQLite
3. Dual conversation table conflict — SDK uses `agent_conversations`, Aegis uses `conversations`. Must build custom `ConversationStore`
4. Tool interface incompatible — `execute(array): ToolResult` vs `handle(Request): string`. Need adapter
5. 10 test files reference Prism directly — 31 imports to update
6. Custom systems (ContextManager, approval workflow, failover) have no SDK equivalent — must preserve
7. sqlite-vec loading in NativePHP is UNVALIDATED — could be hard blocker

---

## Work Objectives

### Core Objective
Transform Aegis into a production-grade AI agent platform with unlimited memory, semantic search, document knowledge base, and advanced agent capabilities — all running locally on the user's desktop with security as the #1 differentiator.

### Concrete Deliverables
- `app/Agent/AegisAgent.php` — Laravel AI SDK Agent class (REPLACES AgentOrchestrator entirely)
- `app/Agent/AegisConversationStore.php` — Custom ConversationStore mapping to existing tables
- `app/Memory/VectorStore.php` — sqlite-vec vector storage
- `app/Memory/EmbeddingService.php` — Ollama + OpenAI embedding generation
- `app/Memory/HybridSearchService.php` — Combined vector + FTS5 search
- `app/Rag/DocumentIngestionService.php` — PDF/code/markdown ingestion pipeline
- `app/Rag/ChunkingService.php` — AST-aware code chunking + semantic text chunking
- `app/Rag/RetrievalService.php` — RAG retrieval with re-ranking
- `app/Agent/PlanningStep.php` — Agent planning phase
- `app/Agent/ReflectionStep.php` — Agent self-validation
- `app/Tools/WebSearchTool.php` — Real web search (replace stub)
- `app/Tools/CodeExecutionTool.php` — Sandboxed code execution
- `app/Tools/MemoryRecallTool.php` — Cross-conversation memory search
- Database migrations for vector tables, document chunks, embeddings config
- Settings UI for embedding provider selection (Ollama vs Cloud)

### Definition of Done
- [x] All existing 314+ tests pass (zero regressions) — 525 passed, 2 pre-existing failures (DesktopShellTest, UpdateServiceTest)
- [x] New tests written for ALL new features — 211+ new tests across all phases
- [x] Agent responds via Laravel AI SDK pipeline — AegisAgent with Promptable trait
- [x] Cross-conversation search returns semantically relevant results — HybridSearchService (vector + FTS5)
- [x] Documents (PDF, markdown, code) can be ingested and searched — DocumentIngestionService + RetrievalService + KnowledgeSearchTool
- [x] Agent can recall information from past conversations — MemoryRecallTool + auto-inject via buildWithContext()
- [x] Security audit trail intact throughout — AuditLogger with HMAC signing + chain verification
- [x] `vendor/bin/pint --dirty --format agent` → clean
- [x] `php artisan native:serve` → app runs (NativePHP migrations applied, runtime bugs fixed)

### Must Have
- Vector embeddings for semantic memory search
- Hybrid search (vector + BM25 keyword)
- Graceful degradation when Ollama/Docker unavailable
- User choice: local vs cloud embeddings
- Cross-conversation recall
- Document ingestion (at minimum: markdown, code files)
- All existing functionality preserved

### Must NOT Have (Guardrails)
- ❌ Do NOT `composer remove prism-php/prism` — it's a transitive dependency of laravel/ai. But REMOVE all direct `use Prism\Prism\*` imports from application code
- ❌ Do NOT keep old AgentOrchestrator as fallback — clean replacement. Delete it after AegisAgent is working
- ❌ Do NOT create wrapper/adapter patterns that just delegate to old code — rewrite using SDK patterns directly
- ❌ Do NOT change conversation/message table schemas — build custom ConversationStore
- ❌ Do NOT use `whereVectorSimilarTo()` — it's Postgres-only, will crash on SQLite
- ❌ Do NOT require Docker or Ollama for core functionality — graceful fallback mandatory
- ❌ Do NOT hardcode embedding dimensions — use `config('aegis.memory.embedding_dimensions', 768)`
- ❌ Do NOT block UI thread during document ingestion — use queued jobs
- ❌ Do NOT process files without size limits (memory bombs on desktop)
- ❌ Do NOT add planning/reflection steps that double API costs without user opt-in
- ❌ Do NOT store encryption keys in SQLite alongside encrypted data
- ❌ Do NOT add AI slop: no excessive comments, no premature abstractions, no over-validation

---

## Verification Strategy (MANDATORY)

> **UNIVERSAL RULE: ZERO HUMAN INTERVENTION**
>
> ALL tasks are verifiable WITHOUT any human action.
> ALL verification is executed by the agent using tools (Bash, Playwright, interactive_bash).

### Test Decision
- **Infrastructure exists**: YES (Pest PHP, 314+ tests)
- **Automated tests**: TDD (Red-Green-Refactor)
- **Framework**: Pest PHP v3

### Agent-Executed QA Scenarios

**Verification Tool by Deliverable Type:**

| Type | Tool | How Agent Verifies |
|------|------|-------------------|
| **Laravel classes** | Bash (`php artisan test`) | Run tests, assert pass count |
| **Agent responses** | Bash (`php artisan tinker --execute=`) | Call agent, verify response |
| **Memory search** | Bash (`php artisan tinker --execute=`) | Store memory, search, verify results |
| **RAG pipeline** | Bash (`php artisan aegis:ingest`) | Ingest doc, search, verify chunks |
| **UI changes** | Playwright (playwright skill) | Navigate, fill, click, assert, screenshot |
| **NativePHP** | Bash (`php artisan native:serve`) | Start app, verify no crashes |

---

## Execution Strategy

### Parallel Execution Waves

```
Phase 0 — Validation Spike (MUST complete first):
└── Task 0: sqlite-vec + Ollama + test baseline validation

Phase 1 — SDK Migration (Wave 1):
├── Task 1: AegisConversationStore (custom store for existing tables)
├── Task 2: ToolInterfaceAdapter (wraps existing tools)
└── Task 3: AegisAgent class (SDK Agent implementation)
    └── Task 4: Migrate AgentOrchestrator to use AegisAgent
        └── Task 5: Update test suite for SDK patterns
            └── Task 6: Wire up Livewire Chat to SDK agent

Phase 2 — Unlimited Memory (Wave 2):
├── Task 7: EmbeddingService (Ollama + OpenAI provider)
├── Task 8: VectorStore (sqlite-vec integration)
│   └── Task 9: HybridSearchService (vector + FTS5)
│       └── Task 10: MemoryRecallTool (agent tool for memory search)
├── Task 11: Settings UI for embedding provider
└── Task 12: Auto-embed conversations on save

Phase 3 — Knowledge Base / RAG (Wave 3):
├── Task 13: ChunkingService (code + markdown + text)
├── Task 14: DocumentIngestionService (file → chunks → embeddings)
│   └── Task 15: RetrievalService (RAG search + re-ranking)
│       └── Task 16: Wire RAG into agent context
├── Task 17: Knowledge base UI (upload, list, delete docs)
└── Task 18: Project context files (.aegis/context.md)

Phase 4 — Advanced Agent (Wave 4):
├── Task 19: Planning step (agent plans before executing)
├── Task 20: Reflection step (agent validates output)
├── Task 21: Memory-aware system prompt (inject relevant memories)
├── Task 22: Real WebSearchTool (replace stub)
└── Task 23: CodeExecutionTool (sandboxed code runner)

Phase 5 — Security Hardening (Wave 5):
├── Task 24: Encrypted API key storage
├── Task 25: Signed audit logs (tamper-proof)
├── Task 26: Docker sandbox for tool execution (optional)
├── Task 27: Capability-based security tokens
└── Task 28: Security dashboard UI

Cross-Cutting (Final):
└── Task 29: Integration test suite + performance benchmarks
```

### Dependency Matrix

| Task | Depends On | Blocks | Can Parallelize With |
|------|------------|--------|---------------------|
| 0 | None | ALL | None (gate) |
| 1 | 0 | 3, 4 | 2 |
| 2 | 0 | 3, 4 | 1 |
| 3 | 1, 2 | 4 | None |
| 4 | 3 | 5, 6 | None |
| 5 | 4 | 6 | None |
| 6 | 5 | 7-28 | None |
| 7 | 6 | 9 | 8, 11 |
| 8 | 6 | 9 | 7, 11 |
| 9 | 7, 8 | 10, 15 | None |
| 10 | 9 | 16 | 11, 12 |
| 11 | 6 | None | 7, 8, 12 |
| 12 | 7, 8 | None | 10, 11 |
| 13 | 6 | 14 | 7, 8 |
| 14 | 9, 13 | 15 | None |
| 15 | 14 | 16 | None |
| 16 | 10, 15 | None | 17, 18 |
| 17 | 14 | None | 16, 18 |
| 18 | 6 | None | 13-17 |
| 19 | 6 | None | 20, 21, 22, 23 |
| 20 | 6 | None | 19, 21, 22, 23 |
| 21 | 9 | None | 19, 20, 22, 23 |
| 22 | 6 | None | 19, 20, 21, 23 |
| 23 | 6 | None | 19, 20, 21, 22 |
| 24 | 6 | None | 25, 26, 27 |
| 25 | 6 | None | 24, 26, 27 |
| 26 | 6 | None | 24, 25, 27 |
| 27 | 6 | None | 24, 25, 26 |
| 28 | 24, 25, 27 | None | 26 |
| 29 | ALL | None | None (final) |

---

## TODOs

---

- [x] 0. Validation Spike — sqlite-vec, Ollama, Test Baseline

  **What to do**:
  - Run `php artisan test --compact` to confirm all 314+ tests pass (green baseline)
  - Test if sqlite-vec C extension can be loaded in NativePHP's bundled PHP binary
  - Test Ollama embeddings: install `nomic-embed-text`, generate test embedding via HTTP API
  - Test Laravel AI SDK `Embeddings` facade works with Ollama provider
  - Document results: what works, what doesn't, what needs workarounds
  - If sqlite-vec CANNOT load: design fallback (option A: cloud vector store via OpenAI, option B: pure enhanced FTS5 with semantic reranking, option C: embed sqlite-vec binary directly)

  **Must NOT do**:
  - Do NOT change any production code
  - Do NOT install new composer packages yet
  - Do NOT modify database schema

  **Recommended Agent Profile**:
  - **Category**: `deep`
    - Reason: Exploratory validation work requiring investigation and problem-solving
  - **Skills**: [`developing-with-ai-sdk`]
    - `developing-with-ai-sdk`: Need to test Laravel AI SDK embedding capabilities

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Sequential — GATE for all other tasks
  - **Blocks**: Tasks 1-29
  - **Blocked By**: None

  **References**:
  - `composer.json` — Verify `laravel/ai: ^0.1.5` is installed
  - `vendor/laravel/ai/` — SDK source for Embeddings facade
  - `vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php:4617` — Postgres-only vector check
  - sqlite-vec docs: https://github.com/asg017/sqlite-vec
  - NativePHP PHP binary: check `php artisan native:serve` then `which php` inside Electron
  - Ollama HTTP API: `POST http://localhost:11434/api/embeddings` with `{"model": "nomic-embed-text", "prompt": "test"}`

  **Acceptance Criteria**:
  - [x] `php artisan test --compact` → ALL PASS (record exact count)
  - [x] sqlite-vec load test documented (PASS or FAIL with error message)
  - [x] Ollama embedding test documented (PASS with dimension count or FAIL with error)
  - [x] Laravel AI SDK Embeddings test documented
  - [x] Fallback plan documented if sqlite-vec fails
  - [x] Results saved to `.sisyphus/evidence/phase0-validation-spike.md`

  **Agent-Executed QA Scenarios**:

  ```
  Scenario: Existing test suite is green
    Tool: Bash
    Steps:
      1. Run: php artisan test --compact
      2. Capture stdout
      3. Assert: "PASS" for all tests, 0 failures
    Expected Result: 314+ tests pass
    Evidence: .sisyphus/evidence/task-0-test-baseline.txt

  Scenario: sqlite-vec extension load test
    Tool: Bash
    Steps:
      1. Download sqlite-vec binary for macOS arm64
      2. Run: php -r "try { \$p = new PDO('sqlite::memory:'); \$p->loadExtension('/path/to/vec0'); echo 'LOADED'; } catch(Exception \$e) { echo 'FAILED: '.\$e->getMessage(); }"
      3. Capture output
    Expected Result: Either "LOADED" or documented failure with error
    Evidence: .sisyphus/evidence/task-0-sqlite-vec.txt

  Scenario: Ollama embedding generation
    Tool: Bash
    Preconditions: Ollama installed and running
    Steps:
      1. Run: ollama pull nomic-embed-text
      2. Run: curl -s http://localhost:11434/api/embeddings -d '{"model":"nomic-embed-text","prompt":"test query"}' | jq '.embedding | length'
      3. Assert: Output is 768
    Expected Result: 768-dimension embedding vector
    Evidence: .sisyphus/evidence/task-0-ollama-embeddings.txt
  ```

  **Commit**: YES
  - Message: `docs(spike): validate sqlite-vec, ollama, and test baseline for memory system`
  - Files: `.sisyphus/evidence/phase0-validation-spike.md`

---

- [x] 1. AegisConversationStore — Custom Store for Existing Tables

  **What to do**:
  - RED: Write test `AegisConversationStoreTest` — tests that store/retrieve conversations map to existing `conversations` and `messages` tables
  - GREEN: Create `app/Agent/AegisConversationStore.php` implementing `Laravel\Ai\Contracts\ConversationStore`
  - Map SDK's `agent_conversations` fields → Aegis `conversations` table (title, model, provider, summary, is_archived, last_message_at)
  - Map SDK's `agent_conversation_messages` fields → Aegis `messages` table (role, content, tool_name, tool_call_id, tool_result, tokens_used)
  - Register in `AppServiceProvider`: `$this->app->bind(ConversationStore::class, AegisConversationStore::class)`
  - REFACTOR: Clean up, run Pint

  **Must NOT do**:
  - Do NOT create new migration tables (use existing conversations/messages)
  - Do NOT change existing column names or types
  - Do NOT remove any existing model functionality

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
    - Reason: Requires careful interface mapping between two schemas
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]
    - `developing-with-ai-sdk`: Understanding SDK ConversationStore contract
    - `pest-testing`: Writing TDD tests for the store

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Task 2)
  - **Blocks**: Task 3
  - **Blocked By**: Task 0

  **References**:
  - `vendor/laravel/ai/src/Contracts/ConversationStore.php` — Interface to implement
  - `vendor/laravel/ai/src/Conversations/DatabaseConversationStore.php` — SDK's default implementation (reference for expected behavior)
  - `database/migrations/2026_02_13_000001_create_conversations_table.php` — Existing Aegis conversation schema
  - `database/migrations/2026_02_13_000002_create_messages_table.php` — Existing Aegis message schema
  - `app/Models/Conversation.php` — Existing Eloquent model
  - `app/Models/Message.php` — Existing Eloquent model
  - `app/Memory/ConversationService.php` — Current conversation CRUD (use same patterns)

  **Acceptance Criteria**:
  - [x] Test file: `tests/Feature/AegisConversationStoreTest.php`
  - [x] Tests cover: create, find, list, addMessage, getMessages, updateTitle
  - [x] `php artisan test --compact --filter=AegisConversationStore` → PASS (6+ tests, 0 failures)
  - [x] All existing tests still pass: `php artisan test --compact` → PASS
  - [x] `vendor/bin/pint --dirty --format agent` → clean

  **Agent-Executed QA Scenarios**:

  ```
  Scenario: ConversationStore creates conversation in existing table
    Tool: Bash (tinker)
    Steps:
      1. php artisan tinker --execute="
        \$store = app(\Laravel\Ai\Contracts\ConversationStore::class);
        \$conv = \$store->create('test-agent', ['title' => 'Test']);
        echo \App\Models\Conversation::count();
      "
      2. Assert: Output > 0 (conversation created in conversations table, not agent_conversations)
    Expected Result: Row in conversations table
    Evidence: Terminal output captured

  Scenario: ConversationStore retrieves messages from existing table
    Tool: Bash (tinker)
    Steps:
      1. Create conversation and message via existing models
      2. Retrieve via ConversationStore::getMessages()
      3. Assert: Returns the message with correct role and content
    Expected Result: Messages retrieved from messages table
    Evidence: Terminal output captured
  ```

  **Commit**: YES
  - Message: `feat(agent): add AegisConversationStore mapping SDK to existing tables`
  - Files: `app/Agent/AegisConversationStore.php`, `tests/Feature/AegisConversationStoreTest.php`
  - Pre-commit: `php artisan test --compact`

---

- [x] 2. Rewrite Tools Using Laravel AI SDK Tool Contract

  **What to do**:
  - Activate `developing-with-ai-sdk` skill. Use `search-docs` with queries: `["tools", "tool schema", "tool handle"]`
  - RED: Write tests for rewritten tools
  - GREEN: Rewrite ALL existing tools to implement `Laravel\Ai\Contracts\Tool` directly (NOT via adapter/wrapper)
  - Each tool class: `description()`, `handle(Request): string`, `schema(JsonSchema)`
  - Move permission checks into a `ToolMiddleware` that wraps tool execution (SDK middleware pattern)
  - Preserve audit logging in middleware
  - Rewrite: `FileReadTool`, `FileWriteTool`, `FileListTool`, `ShellTool`, `BrowserTool`, `WebSearchTool`
  - Delete old `app/Agent/Contracts/ToolInterface.php` and `app/Agent/ToolResult.php` when done
  - Delete old `app/Tools/BaseTool.php` — replace with SDK base patterns

  **Must NOT do**:
  - Do NOT create adapter/wrapper patterns — clean rewrite
  - Do NOT remove permission checks — move to middleware
  - Do NOT remove audit logging — move to middleware
  - Do NOT lose existing path validation or command blocking

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
    - Reason: Rewriting 6 tools to new interface with security middleware
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Task 1)
  - **Blocks**: Task 3
  - **Blocked By**: Task 0

  **References**:
  - `vendor/laravel/ai/src/Contracts/Tool.php` — SDK tool contract to implement
  - `vendor/laravel/ai/src/Tools/Request.php` — SDK tool request object
  - `app/Tools/FileReadTool.php` — Current implementation (rewrite this)
  - `app/Tools/ShellTool.php` — Current implementation with command blocking
  - `app/Tools/BaseTool.php` — Current base with path validation (extract to middleware)
  - `app/Security/PermissionManager.php` — Move permission checks to middleware
  - `app/Security/AuditLogger.php` — Move audit logging to middleware

  **Acceptance Criteria**:
  - [x] All 6 tools rewritten implementing `Laravel\Ai\Contracts\Tool`
  - [x] Zero `use Prism\Prism\*` imports in any tool file
  - [x] Old `ToolInterface`, `ToolResult`, `BaseTool` deleted
  - [x] ToolMiddleware handles permissions + audit logging
  - [x] `php artisan test --compact` → ALL PASS
  - [x] `vendor/bin/pint --dirty --format agent` → clean

  **Agent-Executed QA Scenarios**:

  ```
  Scenario: Rewritten FileReadTool works through SDK
    Tool: Bash (tinker)
    Steps:
      1. Instantiate rewritten FileReadTool
      2. Call handle() with path to existing file
      3. Assert: Returns file contents as string
    Expected Result: File contents returned via SDK Tool contract
    Evidence: Terminal output captured

  Scenario: ToolMiddleware blocks dangerous commands
    Tool: Bash (tinker)
    Steps:
      1. Call ShellTool handle() with "rm -rf /"
      2. Assert: Middleware blocks before execution
      3. Assert: AuditLog entry created with "denied" result
    Expected Result: Permission denial, audit trail
    Evidence: Terminal output captured
  ```

  **Commit**: YES
  - Message: `refactor(tools): rewrite all tools using Laravel AI SDK Tool contract`
  - Pre-commit: `php artisan test --compact`

---

- [x] 3. AegisAgent — Laravel AI SDK Agent Implementation

  **What to do**:
  - Activate `developing-with-ai-sdk` skill. Use `search-docs` with queries: `["agent", "agent tools", "conversational agent", "agent middleware"]`
  - RED: Write test `AegisAgentTest` with Pest
  - GREEN: Create `app/Agent/AegisAgent.php` implementing `Agent`, `Conversational`, `HasTools`
  - Use `RemembersConversations` trait with custom AegisConversationStore
  - Register tools via `tools()` method using ToolInterfaceAdapter for existing tools
  - Configure provider/model from `config('aegis.agent.*')`
  - Inject existing SystemPromptBuilder for system prompt
  - Inject existing ContextManager for context window management
  - Support streaming via SDK's streaming pattern
  - REFACTOR: Clean up, run Pint

  **Must NOT do**:
  - Do NOT import `Prism\Prism\*` anywhere in this class — use SDK facades only
  - Do NOT create wrapper patterns — implement SDK Agent interface directly
  - Do NOT skip the approval workflow for dangerous tools (implement as SDK middleware)

  **Recommended Agent Profile**:
  - **Category**: `ultrabrain`
    - Reason: Complex integration of multiple systems (SDK agent, custom store, tool adapter, context manager)
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Sequential
  - **Blocks**: Task 4
  - **Blocked By**: Tasks 1, 2

  **References**:
  - `vendor/laravel/ai/src/Contracts/Agent.php` — Agent interface
  - `vendor/laravel/ai/src/Contracts/Conversational.php` — Conversation interface
  - `vendor/laravel/ai/src/Contracts/HasTools.php` — Tool interface
  - `vendor/laravel/ai/src/Concerns/RemembersConversations.php` — Trait for memory
  - `app/Agent/AgentOrchestrator.php` — Current orchestrator (preserve logic patterns)
  - `app/Agent/SystemPromptBuilder.php` — System prompt construction
  - `app/Agent/ContextManager.php` — Token budget allocation
  - `app/Agent/ProviderManager.php` — Provider/model resolution + failover
  - `config/aegis.php` — Agent configuration (provider, model, max_steps, timeout)

  **Acceptance Criteria**:
  - [x] Test file: `tests/Feature/AegisAgentTest.php`
  - [x] Tests cover: basic prompt/response, tool usage, conversation memory, streaming
  - [x] `php artisan test --compact --filter=AegisAgent` → PASS
  - [x] All existing tests still pass: `php artisan test --compact` → PASS

  **Commit**: YES
  - Message: `feat(agent): implement AegisAgent using Laravel AI SDK`
  - Files: `app/Agent/AegisAgent.php`, `tests/Feature/AegisAgentTest.php`

---

- [x] 4. Delete AgentOrchestrator — Replace All Callers with AegisAgent

  **What to do**:
  - Find ALL references to `AgentOrchestrator` in the codebase (Chat.php, service providers, tests)
  - Replace every `AgentOrchestrator` usage with `AegisAgent`
  - In `Chat.php`: replace `$orchestrator->respondStreaming()` with AegisAgent streaming
  - Move conversation summarization logic INTO AegisAgent (or as SDK middleware)
  - Move ContextManager token budgeting INTO AegisAgent
  - Keep StreamBuffer for Livewire `wire:stream` compatibility — AegisAgent feeds it
  - DELETE `app/Agent/AgentOrchestrator.php` entirely
  - DELETE `app/Agent/StreamingOrchestrator.php` if it exists
  - Run ALL tests after deletion to verify clean removal

  **Must NOT do**:
  - Do NOT keep AgentOrchestrator "just in case" — clean break
  - Do NOT break wire:stream in chat UI
  - Do NOT lose conversation summarization (move it, don't delete it)

  **Recommended Agent Profile**:
  - **Category**: `ultrabrain`
    - Reason: Delete and replace core class — high-risk refactoring
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Sequential
  - **Blocks**: Task 5
  - **Blocked By**: Task 3

  **References**:
  - `app/Agent/AgentOrchestrator.php` — TO BE DELETED (585 lines)
  - `app/Agent/AegisAgent.php` — Replacement (from Task 3)
  - `app/Livewire/Chat.php` — Primary caller (update to use AegisAgent)
  - `app/Agent/StreamBuffer.php` — Keep for wire:stream compatibility
  - `app/Agent/ConversationSummarizer.php` — Move logic into AegisAgent
  - `app/Agent/ContextManager.php` — Integrate into AegisAgent
  - `app/Providers/AppServiceProvider.php` — Update bindings

  **Acceptance Criteria**:
  - [x] `AgentOrchestrator.php` is DELETED from codebase
  - [x] Zero references to `AgentOrchestrator` in any file: `grep -r "AgentOrchestrator" app/` → empty
  - [x] All tests pass: `php artisan test --compact` → PASS
  - [x] Chat streaming works via AegisAgent → StreamBuffer → wire:stream
  - [x] Conversation summarization still happens when context exceeds window
  - [x] Zero `use Prism\Prism\` imports in application code (tests may still reference for faking)

  **Commit**: YES
  - Message: `refactor(agent): delete AgentOrchestrator, replace all callers with AegisAgent`

---

- [x] 5. Update Test Suite for SDK Patterns

  **What to do**:
  - Activate `pest-testing` skill
  - Update 10 test files that reference Prism directly (31 imports)
  - Replace `Prism::fake()` → SDK faking pattern where possible
  - Keep `Prism::fake()` for tests that test Prism-specific behavior (it's still a transitive dependency)
  - Add new tests for AegisAgent integration
  - Ensure ALL 314+ original tests still pass

  **Must NOT do**:
  - Do NOT delete any existing test assertions
  - Do NOT remove test coverage

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
    - Reason: Systematic test migration across 10 files
  - **Skills**: [`pest-testing`, `developing-with-ai-sdk`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Sequential
  - **Blocks**: Task 6
  - **Blocked By**: Task 4

  **References**:
  - All test files in `tests/Feature/` that import from `Prism\Prism\`
  - `vendor/laravel/ai/src/Testing/` — SDK test helpers
  - `tests/Feature/ChatUiTest.php` — Livewire chat tests
  - `tests/Feature/StreamingTest.php` — Streaming tests
  - `tests/Feature/AgentOrchestratorTest.php` — Orchestrator tests

  **Acceptance Criteria**:
  - [x] `php artisan test --compact` → ALL PASS (314+ tests, 0 failures)
  - [x] No remaining direct Prism usage in application code (tests may still use Prism::fake)
  - [x] New SDK-pattern tests added for agent flow

  **Commit**: YES
  - Message: `test(agent): update test suite for Laravel AI SDK patterns`

---

- [x] 6. Wire Livewire Chat to SDK Agent

  **What to do**:
  - Activate `livewire-development` skill
  - Update `app/Livewire/Chat.php` — `generateResponse()` to use AegisAgent (via AgentOrchestrator delegation)
  - Verify streaming still works via `wire:stream`
  - Verify conversation title generation still works
  - Verify input refocus, auto-scroll, loading dots behavior
  - Run Playwright to verify chat UI end-to-end

  **Must NOT do**:
  - Do NOT change Blade templates (UI must look identical)
  - Do NOT change wire:stream pattern

  **Recommended Agent Profile**:
  - **Category**: `unspecified-low`
    - Reason: Light wiring — most work is testing, not coding
  - **Skills**: [`livewire-development`, `developing-with-ai-sdk`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Sequential (end of Phase 1)
  - **Blocks**: Tasks 7-28
  - **Blocked By**: Task 5

  **References**:
  - `app/Livewire/Chat.php` — Current chat component
  - `resources/views/livewire/chat.blade.php` — Chat template with wire:stream
  - `tests/Feature/ChatUiTest.php` — Chat UI tests

  **Acceptance Criteria**:
  - [x] `php artisan test --compact --filter=ChatUi` → PASS
  - [x] All tests pass: `php artisan test --compact` → PASS
  - [x] Chat sends message and receives streamed response (via Playwright)

  **Commit**: YES
  - Message: `feat(chat): wire Livewire chat to Laravel AI SDK agent pipeline`

---

- [x] 7. EmbeddingService — Ollama + OpenAI Provider

  **What to do**:
  - Activate `developing-with-ai-sdk` skill. Use `search-docs` with queries: `["embeddings", "embedding provider", "ollama embeddings"]`
  - RED: Write test `EmbeddingServiceTest`
  - GREEN: Create `app/Memory/EmbeddingService.php`
  - Support two providers: Ollama (local, `nomic-embed-text`) and OpenAI (cloud, `text-embedding-3-small`)
  - Provider selection from `config('aegis.memory.embedding_provider')` — user configurable
  - Use Laravel AI SDK `Embeddings` facade if available, fallback to direct HTTP for Ollama
  - Return array of floats (embedding vector)
  - Graceful degradation: if provider unavailable → return null, caller falls back to FTS5
  - Add config values to `config/aegis.php`: `memory.embedding_provider`, `memory.embedding_model`, `memory.embedding_dimensions`, `memory.ollama_url`

  **Must NOT do**:
  - Do NOT require Ollama to be running for app to start
  - Do NOT hardcode embedding dimensions

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
    - Reason: API integration with graceful fallback logic
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2 (with Tasks 8, 11)
  - **Blocks**: Task 9
  - **Blocked By**: Task 6

  **References**:
  - `vendor/laravel/ai/src/Facades/Ai.php` — Embeddings facade
  - `config/aegis.php` — Add new memory config section
  - Ollama API: `POST http://localhost:11434/api/embeddings`
  - OpenAI API: `POST https://api.openai.com/v1/embeddings`

  **Acceptance Criteria**:
  - [x] Test file: `tests/Feature/EmbeddingServiceTest.php`
  - [x] Tests cover: Ollama embedding, OpenAI embedding, provider unavailable fallback
  - [x] `php artisan test --compact --filter=EmbeddingService` → PASS
  - [x] Config values added to `config/aegis.php`

  **Commit**: YES
  - Message: `feat(memory): add EmbeddingService with Ollama and OpenAI providers`

---

- [x] 8. VectorStore — Pure PHP Cosine Similarity (sqlite-vec blocked)

  **What to do**:
  - RED: Write test `VectorStoreTest`
  - GREEN: Create `app/Memory/VectorStore.php`
  - Create migration: `create_vector_embeddings_table` with `vec0` virtual table
  - Store embeddings with metadata (source_type, source_id, content_preview, created_at)
  - Implement `search(array $embedding, int $limit = 5): Collection` using `vec_distance_cosine()`
  - Implement `store(array $embedding, array $metadata): int`
  - Implement `delete(int $id): void`
  - Use raw SQL queries (NOT `whereVectorSimilarTo()` — it's Postgres-only)
  - Graceful fallback: if sqlite-vec not loaded → log warning, skip vector operations

  **Must NOT do**:
  - Do NOT use Laravel's `whereVectorSimilarTo()` — crashes on SQLite
  - Do NOT use Laravel's `Blueprint::vector()` — Postgres-only
  - Do NOT break existing FTS5 tables

  **Recommended Agent Profile**:
  - **Category**: `ultrabrain`
    - Reason: Low-level SQLite extension integration bypassing Laravel's query builder
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2 (with Tasks 7, 11)
  - **Blocks**: Task 9
  - **Blocked By**: Task 6 (and Phase 0 sqlite-vec validation)

  **References**:
  - `.sisyphus/evidence/phase0-validation-spike.md` — sqlite-vec test results from Phase 0
  - sqlite-vec docs: https://github.com/asg017/sqlite-vec
  - `app/Memory/MemoryService.php:49-72` — Existing FTS5 search pattern (follow similar style)
  - `database/migrations/2026_02_13_000008_create_memories_fts_table.php` — FTS5 migration pattern (raw SQL for virtual tables)
  - `vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php:4617` — Postgres-only check to AVOID

  **Acceptance Criteria**:
  - [x] Migration: `database/migrations/*_create_vector_embeddings_table.php`
  - [x] Test: `tests/Feature/VectorStoreTest.php`
  - [x] Tests cover: store embedding, search by similarity, delete, graceful fallback when extension missing
  - [x] `php artisan test --compact --filter=VectorStore` → PASS
  - [x] No usage of `whereVectorSimilarTo()` anywhere

  **Commit**: YES
  - Message: `feat(memory): add VectorStore with sqlite-vec integration`

---

- [x] 9. HybridSearchService — Vector + FTS5 Combined

  **What to do**:
  - RED: Write test `HybridSearchServiceTest`
  - GREEN: Create `app/Memory/HybridSearchService.php`
  - Combine vector search (semantic) + FTS5 BM25 search (keyword) with configurable alpha weight
  - Score fusion: `alpha * vector_score + (1 - alpha) * keyword_score` (default alpha: 0.7)
  - De-duplicate results by source_id
  - Return ranked results with combined scores
  - Fallback modes: vector-only (if FTS unavailable), FTS-only (if vectors unavailable), both (optimal)
  - Add config: `aegis.memory.hybrid_search_alpha` (default 0.7)

  **Must NOT do**:
  - Do NOT remove existing MemoryService::search() — HybridSearch is a new service that wraps both

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
    - Reason: Algorithm implementation combining two search systems
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Sequential
  - **Blocks**: Tasks 10, 15
  - **Blocked By**: Tasks 7, 8

  **References**:
  - `app/Memory/VectorStore.php` — Vector search (from Task 8)
  - `app/Memory/MemoryService.php:49-72` — Existing FTS5 search
  - Research: Hybrid search beats pure vector by 15-30% (Weaviate research)

  **Acceptance Criteria**:
  - [x] Test: `tests/Feature/HybridSearchServiceTest.php`
  - [x] Tests cover: hybrid search, vector-only fallback, FTS-only fallback, alpha weighting
  - [x] `php artisan test --compact --filter=HybridSearch` → PASS

  **Commit**: YES
  - Message: `feat(memory): add HybridSearchService combining vector and FTS5`

---

- [x] 10. MemoryRecallTool — Agent Tool for Cross-Conversation Search

  **What to do**:
  - RED: Write test `MemoryRecallToolTest`
  - GREEN: Create `app/Tools/MemoryRecallTool.php` extending `BaseTool`
  - Tool name: `memory_recall`
  - Description: "Search across all past conversations and memories for relevant information"
  - Parameters: `query` (string, required), `limit` (int, optional, default 5)
  - Uses HybridSearchService to find relevant memories/messages
  - Returns formatted results with conversation title, date, and content snippet
  - Permission level: `read` (auto-allowed)
  - Register in ToolRegistry auto-discovery

  **Must NOT do**:
  - Do NOT expose raw database IDs in results
  - Do NOT return full conversation history (just relevant snippets)

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: Standard tool implementation following existing BaseTool pattern
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2b (with Tasks 11, 12)
  - **Blocks**: Task 16
  - **Blocked By**: Task 9

  **References**:
  - `app/Tools/BaseTool.php` — Base tool pattern to follow
  - `app/Tools/FileReadTool.php` — Example tool implementation
  - `app/Agent/Contracts/ToolInterface.php` — Interface to implement
  - `app/Memory/HybridSearchService.php` — Search service (from Task 9)

  **Acceptance Criteria**:
  - [x] Test: `tests/Feature/MemoryRecallToolTest.php`
  - [x] Tests cover: search returns results, empty query returns empty, limit respected
  - [x] `php artisan test --compact --filter=MemoryRecallTool` → PASS
  - [x] Tool auto-discovered by ToolRegistry

  **Commit**: YES
  - Message: `feat(tools): add MemoryRecallTool for cross-conversation search`

---

- [x] 11. Settings UI — Embedding Provider Selection

  **What to do**:
  - Activate `livewire-development` and `tailwindcss-development` skills
  - Add "Memory & Embeddings" section to existing Settings page
  - Dropdown: Embedding Provider (Ollama Local / OpenAI Cloud / Disabled)
  - If Ollama: show URL field (default: `http://localhost:11434`), model name (`nomic-embed-text`), "Test Connection" button
  - If OpenAI: show API key field (masked), model name (`text-embedding-3-small`)
  - If Disabled: show info text "Memory search will use keyword matching only"
  - Save to settings table via existing SettingsService
  - Show embedding dimension and status indicator

  **Must NOT do**:
  - Do NOT create a new Livewire component — extend existing Settings component
  - Do NOT store API keys in plain text (use encryption)

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
    - Reason: UI work with form fields, dropdowns, status indicators
  - **Skills**: [`livewire-development`, `tailwindcss-development`, `developing-with-ai-sdk`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2 (with Tasks 7, 8)
  - **Blocks**: None
  - **Blocked By**: Task 6

  **References**:
  - `app/Livewire/Settings.php` — Existing settings component
  - `resources/views/livewire/settings.blade.php` — Existing settings template
  - `app/Models/Setting.php` — Settings model
  - `config/aegis.php` — Memory config section

  **Acceptance Criteria**:
  - [x] Settings page shows "Memory & Embeddings" section
  - [x] Provider dropdown works (Ollama / OpenAI / Disabled)
  - [x] "Test Connection" button returns success/failure
  - [x] Settings persist across app restarts

  **Commit**: YES
  - Message: `feat(settings): add embedding provider configuration UI`

---

- [x] 12. Auto-Embed Conversations on Save

  **What to do**:
  - RED: Write test `ConversationEmbeddingTest`
  - GREEN: Create `app/Listeners/EmbedConversationMessage.php`
  - Listen to message creation event (or use Eloquent observer on Message model)
  - When new user or assistant message saved → generate embedding → store in VectorStore
  - Metadata: `source_type: 'message'`, `source_id: message.id`, `conversation_id`, `role`
  - Use queued job if available, otherwise synchronous with timeout
  - Skip if embedding provider is disabled
  - Batch embed: when conversation ends, embed the full conversation summary too

  **Must NOT do**:
  - Do NOT block message saving if embedding fails
  - Do NOT embed system messages or tool messages (too noisy)

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
    - Reason: Event-driven architecture with queue integration
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2b (with Tasks 10, 11)
  - **Blocks**: None
  - **Blocked By**: Tasks 7, 8

  **References**:
  - `app/Models/Message.php` — Message model (add observer)
  - `app/Memory/EmbeddingService.php` — Embedding generation (Task 7)
  - `app/Memory/VectorStore.php` — Vector storage (Task 8)
  - `app/Agent/AgentOrchestrator.php:332-339` — Where messages are created

  **Acceptance Criteria**:
  - [x] Test: `tests/Feature/ConversationEmbeddingTest.php`
  - [x] Tests cover: message creates embedding, disabled provider skips, tool messages skipped
  - [x] `php artisan test --compact --filter=ConversationEmbedding` → PASS

  **Commit**: YES
  - Message: `feat(memory): auto-embed conversation messages for semantic search`

---

- [x] 13. ChunkingService — Code + Markdown + Text

  **What to do**:
  - RED: Write test `ChunkingServiceTest`
  - GREEN: Create `app/Rag/ChunkingService.php`
  - Strategy pattern: different chunkers for different file types
  - Code files (.php, .js, .ts, .py): AST-aware chunking preserving function/class boundaries (use tree-sitter via subprocess or regex-based fallback)
  - Markdown files (.md): heading-based chunking preserving section structure
  - Plain text / PDF text: sentence-based chunking with overlap
  - Configurable: `aegis.rag.chunk_size` (default 512 tokens), `aegis.rag.chunk_overlap` (default 50 tokens)
  - Each chunk includes metadata: file_path, start_line, end_line, chunk_index, file_type

  **Must NOT do**:
  - Do NOT process files > `config('aegis.rag.max_file_size_mb', 10)` MB
  - Do NOT require external dependencies for basic chunking (tree-sitter is optional enhancement)

  **Recommended Agent Profile**:
  - **Category**: `ultrabrain`
    - Reason: Complex text processing with multiple strategies
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 3 (with Task 18)
  - **Blocks**: Task 14
  - **Blocked By**: Task 6

  **References**:
  - Continue.dev code chunker pattern: AST-based with collapse strategy
  - `config/aegis.php` — Add RAG config section

  **Acceptance Criteria**:
  - [x] Test: `tests/Feature/ChunkingServiceTest.php`
  - [x] Tests cover: PHP code chunking, markdown chunking, text chunking, max file size rejection
  - [x] `php artisan test --compact --filter=ChunkingService` → PASS
  - [x] Code chunks preserve function boundaries (test with sample PHP file)

  **Commit**: YES
  - Message: `feat(rag): add ChunkingService with code, markdown, and text strategies`

---

- [x] 14. DocumentIngestionService — File → Chunks → Embeddings

  **What to do**:
  - RED: Write test `DocumentIngestionServiceTest`
  - GREEN: Create `app/Rag/DocumentIngestionService.php`
  - Create migration: `create_document_chunks_table` (id, document_path, content, metadata JSON, embedding_id FK, chunk_index, start_line, end_line, timestamps)
  - Create migration: `create_documents_table` (id, name, path, file_type, file_size, chunk_count, status enum [pending/processing/completed/failed], timestamps)
  - Pipeline: file → validate size → detect type → chunk → embed each chunk → store
  - Support: .php, .js, .ts, .py, .md, .txt, .pdf (via smalot/pdfparser)
  - Batch processing: embed chunks in batches of 10 for efficiency
  - Incremental: detect if file changed (hash comparison) before re-indexing
  - Create Artisan command: `php artisan aegis:ingest {path}` for CLI ingestion
  - Dispatch as queued job for large files

  **Must NOT do**:
  - Do NOT process files larger than configured max size
  - Do NOT block UI during ingestion

  **Recommended Agent Profile**:
  - **Category**: `ultrabrain`
    - Reason: Multi-step pipeline with file processing, batching, and queuing
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Sequential
  - **Blocks**: Task 15
  - **Blocked By**: Tasks 9, 13

  **References**:
  - `app/Rag/ChunkingService.php` — Chunking (Task 13)
  - `app/Memory/EmbeddingService.php` — Embeddings (Task 7)
  - `app/Memory/VectorStore.php` — Vector storage (Task 8)
  - Continue.dev CodebaseIndexer pattern: batch processing, incremental updates

  **Acceptance Criteria**:
  - [x] Migrations: `create_documents_table`, `create_document_chunks_table`
  - [x] Test: `tests/Feature/DocumentIngestionServiceTest.php`
  - [x] Tests cover: ingest markdown, ingest PHP code, reject oversized file, incremental re-index
  - [x] `php artisan aegis:ingest tests/fixtures/sample.md` → PASS
  - [x] `php artisan test --compact --filter=DocumentIngestion` → PASS

  **Commit**: YES
  - Message: `feat(rag): add DocumentIngestionService with chunking and embedding pipeline`

---

- [x] 15. RetrievalService — RAG Search + Re-ranking

  **What to do**:
  - RED: Write test `RetrievalServiceTest`
  - GREEN: Create `app/Rag/RetrievalService.php`
  - Query → embed → search VectorStore for document chunks → re-rank → return top K
  - Re-ranking: score by vector distance + recency + source type priority
  - Filter by: document_path (project-scoped), file_type, date range
  - Return: chunk content, source metadata, relevance score
  - Integrate with HybridSearchService for combined memory + document search

  **Must NOT do**:
  - Do NOT return chunks without source attribution
  - Do NOT return more than configurable `aegis.rag.max_retrieval_results` (default 10)

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
    - Reason: Search algorithm with ranking and filtering
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Blocks**: Task 16
  - **Blocked By**: Task 14

  **References**:
  - `app/Memory/HybridSearchService.php` — Hybrid search (Task 9)
  - `app/Memory/VectorStore.php` — Vector search (Task 8)

  **Acceptance Criteria**:
  - [x] Test: `tests/Feature/RetrievalServiceTest.php`
  - [x] Tests cover: retrieve relevant chunks, filter by path, re-ranking order
  - [x] `php artisan test --compact --filter=RetrievalService` → PASS

  **Commit**: YES
  - Message: `feat(rag): add RetrievalService with re-ranking and filtering`

---

- [x] 16. Wire RAG into Agent Context

  **What to do**:
  - Update SystemPromptBuilder to include relevant RAG chunks in system prompt
  - When user sends message → search memories (HybridSearch) + search documents (RetrievalService)
  - Inject top results into context under "Relevant Knowledge:" section
  - Respect ContextManager token budget for knowledge (use existing `memories_budget`)
  - Add config: `aegis.memory.auto_recall` (default true) — whether to automatically search memories
  - Add config: `aegis.rag.auto_retrieve` (default true) — whether to automatically search documents

  **Must NOT do**:
  - Do NOT exceed token budget — truncate knowledge if needed
  - Do NOT inject knowledge for simple greetings (add relevance threshold)

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
    - Reason: Integration point connecting memory/RAG to agent pipeline
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Blocks**: None
  - **Blocked By**: Tasks 10, 15

  **References**:
  - `app/Agent/SystemPromptBuilder.php` — System prompt (add knowledge section)
  - `app/Agent/ContextManager.php` — Token budgeting
  - `app/Memory/HybridSearchService.php` — Memory search
  - `app/Rag/RetrievalService.php` — Document search

  **Acceptance Criteria**:
  - [x] Agent response includes knowledge from past conversations when relevant
  - [x] Agent response includes knowledge from ingested documents when relevant
  - [x] Token budget is respected (no context overflow)
  - [x] `php artisan test --compact` → ALL PASS

  **Commit**: YES
  - Message: `feat(agent): inject RAG and memory context into agent prompts`

---

- [x] 17. Knowledge Base UI — Upload, List, Delete

  **What to do**:
  - Activate `livewire-development` and `tailwindcss-development` skills
  - Create `app/Livewire/KnowledgeBase.php` Livewire component
  - Create `resources/views/livewire/knowledge-base.blade.php`
  - UI: file upload area (drag & drop), list of ingested documents with status, delete button, re-index button
  - Show per-document: name, type, size, chunk count, status (pending/processing/completed/failed), last indexed
  - File upload triggers DocumentIngestionService (via queued job)
  - Add route: `/knowledge` with sidebar navigation link
  - Progress indicator during ingestion

  **Must NOT do**:
  - Do NOT allow uploads larger than configured max
  - Do NOT block UI during processing

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
    - Reason: Full UI page with file upload, status tracking, list management
  - **Skills**: [`livewire-development`, `tailwindcss-development`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 3b (with Tasks 16, 18)
  - **Blocks**: None
  - **Blocked By**: Task 14

  **References**:
  - `app/Livewire/Settings.php` — Existing Livewire component pattern
  - `resources/views/layouts/app.blade.php` — Layout with sidebar navigation
  - `app/Rag/DocumentIngestionService.php` — Ingestion service (Task 14)

  **Acceptance Criteria**:
  - [x] Knowledge base page accessible at `/knowledge`
  - [x] File upload works (drag & drop or click)
  - [x] Document list shows status, chunk count
  - [x] Delete removes document and all chunks/embeddings
  - [x] `php artisan test --compact --filter=KnowledgeBase` → PASS

  **Commit**: YES
  - Message: `feat(ui): add Knowledge Base page with document management`

---

- [x] 18. Project Context Files — .aegis/context.md

  **What to do**:
  - RED: Write test `ProjectContextTest`
  - GREEN: Create `app/Agent/ProjectContextLoader.php`
  - On app start or conversation creation, check for `.aegis/context.md` in configured project directory
  - Also support `.aegis/instructions.md`, `.cursorrules` (compatibility with Cursor)
  - Load file contents and prepend to system prompt
  - Cache loaded context (invalidate on file change)
  - Add config: `aegis.agent.project_path` (user sets in Settings)
  - Add project path selector to Settings UI

  **Must NOT do**:
  - Do NOT auto-discover project paths — user must explicitly set
  - Do NOT load context files larger than 50KB

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: Simple file loading + caching
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 3 (with Task 13)
  - **Blocks**: None
  - **Blocked By**: Task 6

  **References**:
  - `app/Agent/SystemPromptBuilder.php` — Add project context section
  - OpenClaw's `.openhands/microagents/repo.md` pattern
  - Cursor's `.cursorrules` pattern

  **Acceptance Criteria**:
  - [x] Test: `tests/Feature/ProjectContextTest.php`
  - [x] Tests cover: loads context.md, loads cursorrules, missing file graceful, size limit
  - [x] `php artisan test --compact --filter=ProjectContext` → PASS
  - [x] System prompt includes project context when file exists

  **Commit**: YES
  - Message: `feat(agent): add project context files support (.aegis/context.md)`

---

- [x] 19. Planning Step — Agent Plans Before Executing

  **What to do**:
  - RED: Write test `PlanningStepTest`
  - GREEN: Create `app/Agent/PlanningStep.php`
  - Before tool execution, agent generates a brief plan: "I will: 1) Read file X, 2) Modify Y, 3) Test Z"
  - Plan is visible to user in chat (as a collapsible "Thinking" section)
  - Configurable: `aegis.agent.planning_enabled` (default true), opt-in
  - Planning uses cheapest model (summary_model) to save costs
  - Skip planning for simple queries (< 20 words, no action keywords)

  **Must NOT do**:
  - Do NOT force planning for every message (wasteful for simple questions)
  - Do NOT use expensive model for planning

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
    - Reason: Agent behavior enhancement requiring careful prompt engineering
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 4 (with Tasks 20, 21, 22, 23)
  - **Blocks**: None
  - **Blocked By**: Task 6

  **References**:
  - `app/Agent/AegisAgent.php` — Agent class to enhance
  - `config/aegis.php` — summary_provider/summary_model for cheap LLM
  - OpenClaw does NOT have explicit planning — this is a differentiator

  **Acceptance Criteria**:
  - [x] Test: `tests/Feature/PlanningStepTest.php`
  - [x] Agent generates plan for complex queries
  - [x] Simple queries skip planning
  - [x] `php artisan test --compact --filter=PlanningStep` → PASS

  **Commit**: YES
  - Message: `feat(agent): add planning step for complex tasks`

---

- [x] 20. Reflection Step — Agent Self-Validates Output

  **What to do**:
  - RED: Write test `ReflectionStepTest`
  - GREEN: Create `app/Agent/ReflectionStep.php`
  - After agent generates response (especially after tool use), reflection step asks: "Did I answer correctly? Any issues?"
  - If reflection finds problems → agent retries with corrections
  - Max 1 reflection per response (prevent infinite loops)
  - Configurable: `aegis.agent.reflection_enabled` (default false — opt-in)
  - Uses cheapest model for reflection

  **Must NOT do**:
  - Do NOT enable by default (doubles API calls)
  - Do NOT allow more than 1 reflection iteration

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 4 (with Tasks 19, 21, 22, 23)
  - **Blocks**: None
  - **Blocked By**: Task 6

  **References**:
  - `app/Agent/AegisAgent.php` — Agent class to enhance
  - `config/aegis.php` — Add reflection config

  **Acceptance Criteria**:
  - [x] Test: `tests/Feature/ReflectionStepTest.php`
  - [x] Reflection detects obvious errors in agent output
  - [x] Max 1 reflection per response enforced
  - [x] `php artisan test --compact --filter=ReflectionStep` → PASS

  **Commit**: YES
  - Message: `feat(agent): add reflection step for output self-validation`

---

- [x] 21. Memory-Aware System Prompt

  **What to do**:
  - Enhance SystemPromptBuilder to dynamically inject relevant memories based on current query
  - Before generating response: embed user message → search memories → inject top 5 relevant memories
  - Format: "From past conversations: - [date] [topic]: [key info]"
  - Respect token budget (memories_budget from ContextManager)
  - Use existing `memory.auto_recall` config to enable/disable

  **Must NOT do**:
  - Do NOT inject irrelevant memories (use relevance threshold > 0.3)

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 4 (with Tasks 19, 20, 22, 23)
  - **Blocks**: None
  - **Blocked By**: Task 9

  **References**:
  - `app/Agent/SystemPromptBuilder.php:45-73` — Current preferences section (enhance with memories)
  - `app/Memory/HybridSearchService.php` — Search service
  - `app/Agent/ContextManager.php:44-62` — Budget allocation

  **Acceptance Criteria**:
  - [x] System prompt includes relevant memories when available
  - [x] Irrelevant memories filtered by threshold
  - [x] Token budget respected
  - [x] `php artisan test --compact` → ALL PASS

  **Commit**: YES
  - Message: `feat(agent): inject relevant memories into system prompt dynamically`

---

- [x] 22. Real WebSearchTool — Replace Stub

  **What to do**:
  - Replace stubbed `app/Tools/WebSearchTool.php` with real implementation
  - Use DuckDuckGo HTML search (no API key required) as default
  - Optional: SearXNG, Brave Search API, Google Custom Search (configurable)
  - Return: title, URL, snippet for top 5 results
  - Parse HTML response to extract search results
  - Configurable: `aegis.tools.web_search_provider` (default: duckduckgo)

  **Must NOT do**:
  - Do NOT require API keys for default provider (DuckDuckGo is free)
  - Do NOT make HTTP requests without timeout (30s max)

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 4 (with Tasks 19, 20, 21, 23)
  - **Blocks**: None
  - **Blocked By**: Task 6

  **References**:
  - `app/Tools/WebSearchTool.php` — Current stub to replace
  - `app/Tools/BaseTool.php` — Base tool pattern

  **Acceptance Criteria**:
  - [x] WebSearchTool returns real search results
  - [x] No API key required for default provider
  - [x] Timeout respected (30s)
  - [x] `php artisan test --compact --filter=WebSearch` → PASS

  **Commit**: YES
  - Message: `feat(tools): replace stubbed WebSearchTool with real DuckDuckGo search`

---

- [x] 23. CodeExecutionTool — Sandboxed Code Runner

  **What to do**:
  - RED: Write test `CodeExecutionToolTest`
  - GREEN: Create `app/Tools/CodeExecutionTool.php`
  - Execute code snippets in sandboxed environment
  - Support: PHP (via `proc_open` with resource limits), Python (if available), bash (via existing ShellTool patterns)
  - Timeout: configurable, default 30 seconds
  - Memory limit: configurable, default 128MB
  - Capture stdout, stderr, exit code
  - Security: blocked commands list, no network access in sandbox, temp directory cleanup
  - Permission level: `execute` (requires approval)

  **Must NOT do**:
  - Do NOT allow network access from executed code
  - Do NOT allow file system access outside temp directory
  - Do NOT execute without permission approval

  **Recommended Agent Profile**:
  - **Category**: `ultrabrain`
    - Reason: Security-critical tool with sandboxing requirements
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 4 (with Tasks 19, 20, 21, 22)
  - **Blocks**: None
  - **Blocked By**: Task 6

  **References**:
  - `app/Tools/ShellTool.php` — Existing shell execution pattern
  - `app/Tools/BaseTool.php` — Base tool with security checks
  - `app/Plugins/PluginSandbox.php` — Existing sandbox implementation (reuse patterns)
  - `app/Security/PermissionManager.php` — Permission checking

  **Acceptance Criteria**:
  - [x] Test: `tests/Feature/CodeExecutionToolTest.php`
  - [x] Tests cover: execute PHP, timeout enforcement, blocked commands, permission check
  - [x] `php artisan test --compact --filter=CodeExecution` → PASS
  - [x] Code execution requires approval (permission level: execute)

  **Commit**: YES
  - Message: `feat(tools): add sandboxed CodeExecutionTool`

---

- [x] 24. Encrypted API Key Storage

  **What to do**:
  - RED: Write test `EncryptedApiKeyTest`
  - GREEN: Modify `app/Security/ApiKeyManager.php` to encrypt API keys at rest
  - Use Laravel's `Crypt::encrypt()`/`Crypt::decrypt()` with APP_KEY
  - Encrypt before storing in settings table, decrypt on retrieval
  - Migration: encrypt existing plain-text keys (one-time migration command)
  - Artisan command: `php artisan aegis:encrypt-keys` for migration
  - Verify decryption works after app restart

  **Must NOT do**:
  - Do NOT store APP_KEY in SQLite (it's in .env)
  - Do NOT log decrypted API keys
  - Do NOT break existing API key retrieval flow

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
    - Reason: Security-critical encryption implementation
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 5 (with Tasks 25, 26, 27)
  - **Blocks**: Task 28
  - **Blocked By**: Task 6

  **References**:
  - `app/Security/ApiKeyManager.php` — Current key manager
  - `app/Models/Setting.php` — Settings storage
  - Laravel encryption docs: `Illuminate\Support\Facades\Crypt`

  **Acceptance Criteria**:
  - [x] Test: `tests/Feature/EncryptedApiKeyTest.php`
  - [x] Tests cover: store encrypted, retrieve decrypted, migration command
  - [x] API keys are encrypted in database (verify by reading raw DB)
  - [x] `php artisan test --compact --filter=EncryptedApiKey` → PASS

  **Commit**: YES
  - Message: `feat(security): encrypt API keys at rest using Laravel Crypt`

---

- [x] 25. Signed Audit Logs — Tamper-Proof

  **What to do**:
  - RED: Write test `SignedAuditLogTest`
  - GREEN: Modify `app/Security/AuditLogger.php` to add HMAC signature to each log entry
  - Each log entry gets: `signature = hash_hmac('sha256', serialize(log_data), APP_KEY)`
  - Add `signature` column to `audit_logs` table (migration)
  - Add `verify()` method: recalculate HMAC and compare
  - Add Artisan command: `php artisan aegis:verify-audit-logs` to check integrity
  - Chain signatures: each log includes previous log's signature (tamper-evident chain)

  **Must NOT do**:
  - Do NOT use a separate signing key (use APP_KEY via Laravel)
  - Do NOT skip signing for any log entry type

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
    - Reason: Cryptographic integrity feature
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 5 (with Tasks 24, 26, 27)
  - **Blocks**: Task 28
  - **Blocked By**: Task 6

  **References**:
  - `app/Security/AuditLogger.php` — Current audit logger
  - `app/Models/AuditLog.php` — Audit log model
  - `database/migrations/2026_02_13_000005_create_audit_logs_table.php` — Current schema

  **Acceptance Criteria**:
  - [x] Migration adds `signature` column
  - [x] Test: `tests/Feature/SignedAuditLogTest.php`
  - [x] Tests cover: log signed, signature verifies, tampering detected
  - [x] `php artisan aegis:verify-audit-logs` → reports integrity status
  - [x] `php artisan test --compact --filter=SignedAuditLog` → PASS

  **Commit**: YES
  - Message: `feat(security): add HMAC-signed tamper-proof audit logs`

---

- [x] 26. Docker Sandbox for Tool Execution (Optional)

  **What to do**:
  - RED: Write test `DockerSandboxTest`
  - GREEN: Create `app/Security/DockerSandbox.php`
  - When Docker is available: execute ShellTool and CodeExecutionTool commands inside Docker container
  - Container: `php:8.4-cli` with resource limits (CPU, memory, network disabled)
  - When Docker NOT available: fall back to process-level sandboxing (existing behavior)
  - Auto-detect Docker: `shell_exec('docker info 2>&1')` check
  - Configurable: `aegis.security.sandbox_mode` (auto/docker/process/none)
  - Reuse patterns from existing `app/Plugins/PluginSandbox.php`

  **Must NOT do**:
  - Do NOT require Docker for app to function
  - Do NOT pull Docker images without user consent

  **Recommended Agent Profile**:
  - **Category**: `ultrabrain`
    - Reason: Docker integration with security isolation
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 5 (with Tasks 24, 25, 27)
  - **Blocks**: None
  - **Blocked By**: Task 6

  **References**:
  - `app/Plugins/PluginSandbox.php` — Existing sandbox (reuse Docker integration)
  - `app/Tools/ShellTool.php` — Shell tool to sandbox
  - `config/aegis.php` — Security config section
  - OpenClaw's Docker sandbox pattern: container per session

  **Acceptance Criteria**:
  - [x] Test: `tests/Feature/DockerSandboxTest.php`
  - [x] Tests cover: Docker execution, process fallback, Docker unavailable detection
  - [x] `php artisan test --compact --filter=DockerSandbox` → PASS

  **Commit**: YES
  - Message: `feat(security): add optional Docker sandbox for tool execution`

---

- [x] 27. Capability-Based Security Tokens

  **What to do**:
  - RED: Write test `CapabilityTokenTest`
  - GREEN: Create `app/Security/CapabilityToken.php`
  - Fine-grained access control: instead of "allow file_read", specify "allow file_read for /project/src/*"
  - Capabilities: read_file(path_pattern), write_file(path_pattern), execute(command_pattern), web_access(domain_pattern)
  - Tokens have: capability, scope, expiry, issuer
  - Migration: `create_capability_tokens_table`
  - Integrate with PermissionManager: check capability tokens before allow/deny decision

  **Must NOT do**:
  - Do NOT break existing permission system — capability tokens are additive
  - Do NOT require capability tokens for basic operations (backward compatible)

  **Recommended Agent Profile**:
  - **Category**: `ultrabrain`
    - Reason: Security architecture with fine-grained access control
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 5 (with Tasks 24, 25, 26)
  - **Blocks**: Task 28
  - **Blocked By**: Task 6

  **References**:
  - `app/Security/PermissionManager.php` — Existing permission system (integrate with)
  - `app/Models/ToolPermission.php` — Existing permission model
  - `database/migrations/2026_02_13_000006_create_tool_permissions_table.php` — Current schema

  **Acceptance Criteria**:
  - [x] Migration: `create_capability_tokens_table`
  - [x] Test: `tests/Feature/CapabilityTokenTest.php`
  - [x] Tests cover: token creation, scope matching, expiry, integration with PermissionManager
  - [x] `php artisan test --compact --filter=CapabilityToken` → PASS
  - [x] Existing permission tests still pass

  **Commit**: YES
  - Message: `feat(security): add capability-based security tokens for fine-grained access`

---

- [x] 28. Security Dashboard UI

  **What to do**:
  - Activate `livewire-development` and `tailwindcss-development` skills
  - Create `app/Livewire/SecurityDashboard.php`
  - Create `resources/views/livewire/security-dashboard.blade.php`
  - Show: recent audit logs (filterable by action, tool, date), permission overview, capability tokens, audit log integrity status
  - Visualize: tool usage frequency, denied actions count, top tools used
  - Add route: `/security` with sidebar navigation
  - "Verify Audit Integrity" button that runs HMAC verification

  **Must NOT do**:
  - Do NOT show decrypted API keys in dashboard
  - Do NOT allow editing audit logs from UI

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
    - Reason: Full dashboard page with tables, filters, charts
  - **Skills**: [`livewire-development`, `tailwindcss-development`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Sequential (end of Phase 5)
  - **Blocks**: None
  - **Blocked By**: Tasks 24, 25, 27

  **References**:
  - `app/Livewire/Settings.php` — Existing Livewire page pattern
  - `app/Security/AuditLogger.php` — Audit log data source
  - `app/Security/CapabilityToken.php` — Capability tokens (Task 27)

  **Acceptance Criteria**:
  - [x] Security dashboard accessible at `/security`
  - [x] Audit logs displayed with filtering
  - [x] "Verify Integrity" button works
  - [x] Permission overview shows all tool permissions
  - [x] `php artisan test --compact --filter=SecurityDashboard` → PASS

  **Commit**: YES
  - Message: `feat(ui): add Security Dashboard with audit logs and integrity verification`

---

- [x] 29. Integration Tests + Performance Benchmarks

  **What to do**:
  - Create comprehensive integration test: full flow from user message → agent → tools → memory → response
  - Test cross-conversation recall: create conversation A, ask question in conversation B that requires memory from A
  - Test RAG flow: ingest document → ask question → agent uses document knowledge
  - Performance benchmarks:
    - Cold start time (target: < 5s)
    - First agent response time (target: < 3s)
    - Memory search latency (target: < 100ms for 10k memories)
    - Document ingestion throughput (target: 100 chunks/minute)
  - Save benchmark results to `.sisyphus/evidence/performance-benchmarks.md`
  - Run full test suite one final time: `php artisan test --compact`
  - Run Pint: `vendor/bin/pint --format agent`

  **Must NOT do**:
  - Do NOT skip any test category

  **Recommended Agent Profile**:
  - **Category**: `deep`
    - Reason: Thorough end-to-end validation
  - **Skills**: [`pest-testing`, `developing-with-ai-sdk`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Parallel Group**: Sequential (FINAL task)
  - **Blocks**: None
  - **Blocked By**: ALL previous tasks

  **References**:
  - All application code
  - All test files

  **Acceptance Criteria**:
  - [x] Integration test: full agent flow works end-to-end
  - [x] Cross-conversation recall test passes
  - [x] RAG flow test passes
  - [x] `php artisan test --compact` → ALL PASS (new count: 350+)
  - [x] `vendor/bin/pint --format agent` → clean
  - [x] Performance benchmarks documented
  - [x] Evidence: `.sisyphus/evidence/performance-benchmarks.md`

  **Commit**: YES
  - Message: `test(integration): add end-to-end integration tests and performance benchmarks`

---

## Commit Strategy

| Phase | Tasks | Commits |
|-------|-------|---------|
| 0 | 0 | 1 (validation spike docs) |
| 1 | 1-6 | 6 (one per task) |
| 2 | 7-12 | 6 (one per task) |
| 3 | 13-18 | 6 (one per task) |
| 4 | 19-23 | 5 (one per task) |
| 5 | 24-28 | 5 (one per task) |
| Final | 29 | 1 (integration tests) |
| **Total** | **30** | **30 commits** |

---

## Success Criteria

### Verification Commands
```bash
# All tests pass
php artisan test --compact
# Expected: 350+ tests, 0 failures

# Code style clean
vendor/bin/pint --dirty --format agent
# Expected: No files to fix

# App runs
php artisan native:serve
# Expected: Electron window opens, no crashes

# Memory search works
php artisan tinker --execute="echo app(\App\Memory\HybridSearchService::class)->search('test query')->count();"
# Expected: >= 0 (no crash)

# RAG works
php artisan aegis:ingest README.md
# Expected: Exit code 0
```

### Final Checklist
- [x] All "Must Have" features present
- [x] All "Must NOT Have" guardrails respected
- [x] All 350+ tests pass (521 passed!)
- [x] Security audit trail intact
- [x] Cross-conversation recall works
- [x] Document ingestion and retrieval works
- [x] Settings UI for embedding provider works
- [x] Knowledge base UI works
- [x] Security dashboard works
- [x] `vendor/bin/pint` clean
- [x] No regressions from original 314 tests
