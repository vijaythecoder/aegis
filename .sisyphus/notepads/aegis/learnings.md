- 2026-02-13: Memory service layer added under `App\Memory` with conversation CRUD, message history/token tracking, and memory dedupe by `type+key`.
- 2026-02-13: FTS5 memory search works reliably by joining `memories` with `memories_fts` and preserving ranked row order in PHP.
- 2026-02-13: Auto-title behavior is safest in `MessageService::store` on first message when conversation title is blank, using 50-char truncation.
- 2026-02-13: Fact extraction phase uses regex-only patterns (`My name is`, `I prefer`, `I use`) and persists via `MemoryService` without LLM calls.
- 2026-02-13: Prism streaming in this codebase uses `Prism::text()->...->asStream()` and token chunks arrive as `TextDeltaEvent::$delta` values.
- 2026-02-13: Cache-backed `StreamBuffer` with key `stream:{conversationId}` works cleanly for Livewire polling (`wire:poll.500ms`) and stop/cancel state handoff.
- 2026-02-13: Partial assistant responses can be persisted without schema changes by storing streaming completion metadata in `messages.tool_result` (e.g., `is_complete`, `cancelled`, `streamed`).

- 2026-02-13: Context budget ratios in `config('aegis.context.*')` map cleanly to `allocateBudget()` with flooring and remainder assigned to message budget.
- 2026-02-13: Tool-role messages can be aggressively compressed by content-type heuristics (JSON/file/shell) while preserving enough context for follow-up turns.
- 2026-02-13: Sliding context windows are stable when summary+memories are injected as synthetic `system` messages before recent verbatim history.
- 2026-02-13: Multi-provider support works best when provider/model resolution happens once per request and is reused for context truncation, tool gating, and Prism invocation.
- 2026-02-13: Failover is easiest to verify by logging provider fallback events and wrapping provider-specific retries inside a ProviderManager failover chain.

- 2026-02-13: Messaging adapter contracts stabilize platform integrations when `IncomingMessage` and `AdapterCapabilities` are immutable value objects reused by all adapters.
- 2026-02-13: `SessionBridge` should persist a unique `platform + platform_channel_id` mapping to conversation IDs so webhook turns resume context without duplicating conversations.
- 2026-02-13: Registering `PluginManager` with `bind(..., $parameters)` preserves test overrides (`pluginsPath`) while still letting commands resolve the manager through the container.
- 2026-02-13: Loading webhook routes from `AppServiceProvider::boot()` keeps messaging endpoints isolated in `routes/messaging.php` without touching `bootstrap/app.php` route wiring.

- 2026-02-13: Plugin manifests are safest when validated eagerly via `PluginManifest::fromPath()` and rejected before discovery registration.
- 2026-02-13: Dynamic plugin PSR-4 autoload + provider registration works reliably once `ToolRegistry` is container-singleton so plugin boot registrations persist.
- 2026-02-13: Plugin enable/disable state is stable when persisted in `settings(group=plugins,key=enabled)` as JSON array with config fallback when DB is unavailable.
- 2026-02-13: Browser automation in this codebase can stay dependency-light by bridging to Node Playwright with `Symfony\Component\Process\Process` and JSON payload exchange.
- 2026-02-13: `BrowserTool` testability is best when `BrowserSession` is constructor-injected and swapped via `app()->instance(...)` in feature tests.
- 2026-02-13: Session tab limiting is stable when `openTab()` enforces a fixed cap and evicts the oldest tracked tab before adding a new one.
- 2026-02-13: Discord interaction adapters can short-circuit webhook routing cleanly by throwing HttpResponseException for PING (type=1) and invalid signatures, while normal type=2 commands return IncomingMessage for MessageRouter flow.

- 2026-02-13: End-to-end webhook tests are stable when overriding `MessageRouter` adapters via `app()->forgetInstance(MessageRouter::class)` and re-registering test adapters before each request.
- 2026-02-13: Phase 2 browser tool loops in `AgentOrchestrator` need explicit approval-resolver allow closures in tests because `BrowserTool` uses `requiredPermission()` = `browser` (not auto-allowed like `read`).
- 2026-02-13: Runtime plugin fixtures can validate full discover→load→tool-call flows by writing temporary `plugin.json` + PSR-4 classes under `storage/framework/testing`, then loading via `PluginManager` with a custom `pluginsPath` parameter.
- 2026-02-13: Streaming/webhook/plugin latency checks are deterministic with `Prism::fake()` and lightweight adapter fakes, and current thresholds pass comfortably (`<500ms`, `<2s`, `<100ms`).

- 2026-02-13: Marketplace registry caching is cleanest when `PluginRegistry::sync()` uses DB `updated_at` freshness checks plus explicit `sync(true)` for manual refresh actions.
- 2026-02-13: Marketplace install flow composes well with existing plugin infrastructure by resolving remote `source` via `Http` then delegating installation/enabling to `PluginInstaller` + `PluginManager`.
- 2026-02-13: Marketplace API tests stay deterministic by faking both list (`/plugins`) and install metadata (`/plugins/{name}/download`) endpoints and using local manifest fixtures under `storage/framework/testing`.

- 2026-02-13: Meta webhook query keys arrive as `hub_mode`, `hub_verify_token`, and `hub_challenge` in Laravel request parsing, so verification routes should read underscore keys (and optionally dot fallbacks).
- 2026-02-13: WhatsApp Cloud adapter integration fits existing messaging flow by validating `X-Hub-Signature-256` in `handleIncomingMessage()`, mapping `entry.0.changes.0.value.messages.0` into `IncomingMessage`, and chunking outbound text at 1024 chars.
- 2026-02-13: WhatsApp 24-hour enforcement can be implemented without schema changes by resolving `messaging_channels(platform=whatsapp, platform_channel_id)` to `conversation_id` and checking newest `messages(role=user).created_at` against `now()->subDay()` before outbound API calls.
- 2026-02-13: In tests, Eloquent `create()` ignores non-fillable `created_at`/`updated_at`; use `forceFill(...)->save()` after creation to seed deterministic window-boundary timestamps.
- 2026-02-13: Keeping marketplace OpenAPI payload fields aligned to `MarketplacePlugin::$fillable` avoids contract drift between registry responses and local cache sync.

- 2026-02-13: Plugin signature hashing is stable when files are sorted by normalized relative path and `plugin.json` is canonicalized with `signature`/`public_key` removed before hashing.
- 2026-02-13: Trust tiering is straightforward with detached Ed25519 checks: `verified_by_aegis` when manifest key matches trusted local public key, `author_signed` for other valid keys, and `unsigned` when metadata is absent.
- 2026-02-13: Install safety works best by enforcing verification inside `PluginInstaller` (block tampered, allow unsigned with warning), so both direct plugin installs and marketplace installs share the same guardrail.

- 2026-02-13: Slack request verification should validate both `X-Slack-Signature` HMAC (`v0:{timestamp}:{raw_body}`) and `X-Slack-Request-Timestamp` freshness (<=5 minutes) before parsing payloads.
- 2026-02-13: Slack webhook payloads can arrive as JSON (Events API) or `application/x-www-form-urlencoded` (slash commands), so adapter parsing should branch on `Content-Type` and use `parse_str` for form bodies.
- 2026-02-13: Thread reply support can be added without changing adapter contracts by encoding thread context in `IncomingMessage::channelId` and decoding in the platform adapter before outbound `chat.postMessage`.
- 2026-02-13: Slack URL verification is safest handled at route-level with early challenge response so no router/orchestrator work occurs before the verification handshake completes.

- 2026-02-14: AegisAgent is clean and minimal (70 lines) because the SDK's `Promptable` trait provides all public API methods (`prompt()`, `stream()`, `queue()`, `broadcast()`, `fake()`, `assertPrompted()`). The agent class only needs to implement configuration methods.
- 2026-02-14: `RemembersConversations` trait is auto-detected by `GeneratesText::gatherMiddlewareFor()` which adds `RememberConversation` middleware when `hasConversationParticipant()` returns true. No need to manually add it to `middleware()`.
- 2026-02-14: `TextGenerationOptions::forAgent()` reads `#[MaxSteps]`, `#[MaxTokens]`, `#[Temperature]` PHP attributes from the agent class via reflection. These must be compile-time constants.
- 2026-02-14: `AegisAgent::fake()` returns a `FakeTextGateway` that intercepts all `prompt()` and `stream()` calls. The fake streams by splitting text on spaces into `TextDelta` events.
- 2026-02-14: When adding constructor dependencies to AegisAgent, any test that used `new AegisAgent` directly must be updated to `app(AegisAgent::class)` to get DI resolution.

- 2026-02-14: Task 4-6 Phase 1 completion learnings:
- 2026-02-14: `MessageRouter::route()` must call `$agent->continue($conversationId, $participant)` before `prompt()` to associate the conversation. Without this, the SDK's conversation store middleware won't save messages.
- 2026-02-14: `RemembersConversations::continue()` requires 2 args: `(string $conversationId, object $as)` — the `$as` object needs an `id` property. For messaging webhooks, use `(object) ['id' => $senderId]`.
- 2026-02-14: Webhook integration tests that used `Prism::fake()` must switch to `AegisAgent::fake()` after SDK migration. `Prism::fake()` only intercepts direct Prism calls, not SDK agent calls.
- 2026-02-14: `AegisAgent::fake()` bypasses the conversation store middleware, so tests can't assert DB message persistence. Use `AegisAgent::assertPrompted()` instead to verify the prompt was received.
- 2026-02-14: Plugin system (`PluginSandbox`, `PluginServiceProvider`, `McpToolAdapter`) still uses old `ToolInterface`/`ToolResult`/`BaseTool` — these cannot be deleted yet. `ToolRegistry` supports dual registration (SDK Tool + legacy BaseTool).
- 2026-02-14: Chat.php streaming pattern: `$agent->stream($text)` returns `StreamableAgentResponse`, iterate with `foreach ($stream as $event)`, check `$event instanceof TextDelta`, use `$event->delta` for `$this->stream(to: 'streamedResponse', content: ...)`. After iteration, `$stream->text` has full combined text.
- 2026-02-14: Phase 1 final test count: 365 passed, 1 pre-existing failure (DesktopShellTest). Started at 385 tests — deleted ~29 tests that tested deleted AgentOrchestrator functionality, added ~9 new SDK-pattern tests.

- 2026-02-14: Phase 2 Unlimited Memory learnings:
- 2026-02-14: `Embeddings::for([$text])->dimensions($dim)->generate($provider, $model)` returns `EmbeddingsResponse`. Use `->first()` for single embedding, `->embeddings` for batch.
- 2026-02-14: `Embeddings::fake()` auto-generates random normalized vectors when no explicit response provided. `Embeddings::fakeEmbedding(768)` generates a single random 768-dim vector.
- 2026-02-14: `Embeddings::assertGenerated(fn ($prompt) => ...)` receives `EmbeddingsPrompt` with `$prompt->inputs`, `$prompt->dimensions`, `$prompt->provider`, `$prompt->model`. Provider has `->name()` method.
- 2026-02-14: sqlite-vec is BLOCKED in NativePHP — pure PHP cosine similarity works as fallback. Embeddings stored as binary BLOB via `pack('f*', ...)` and decoded with `unpack('f*', ...)`.
- 2026-02-14: Cosine similarity in PHP: `dot_product / (magnitude_a * magnitude_b)`. For 768-dim vectors, performance is acceptable for <10k embeddings.
- 2026-02-14: HybridSearchService score fusion: `alpha * vector_score + (1 - alpha) * fts_score`. Default alpha 0.7 (vector-weighted). Deduplication by `source_type_source_id` key.
- 2026-02-14: `JsonSchema` interface (`Illuminate\Contracts\JsonSchema\JsonSchema`) cannot be resolved from container — use concrete `Illuminate\JsonSchema\JsonSchema` in tests, or skip schema testing.
- 2026-02-14: ToolRegistry auto-discovers any class in `app/Tools/` implementing `Laravel\Ai\Contracts\Tool` via Symfony Finder. New tools just need to be placed in the directory.
- 2026-02-14: EmbedConversationMessage listener only embeds `user` and `assistant` roles — skips `system` and `tool` messages to reduce noise.
- 2026-02-14: Phase 2 final test count: 410 passed (45 new tests added), 1 pre-existing failure (DesktopShellTest).

- 2026-02-14: Phase 3 Knowledge Base / RAG learnings:
- 2026-02-14: ChunkingService uses strategy pattern: PHP code (brace-depth boundary detection), generic code (regex patterns per language), markdown (heading-based), text (sentence-based with word overlap).
- 2026-02-14: DocumentIngestionService pipeline: file → validate size → detect type → chunk → batch embed (10 per batch) → store chunks with embedding IDs. Incremental re-indexing via SHA-256 content hash comparison.
- 2026-02-14: RetrievalService searches VectorStore for `source_type=document_chunk`, then resolves actual chunk content via DocumentChunk model. Falls back to LIKE text search when embeddings disabled.
- 2026-02-14: SystemPromptBuilder.buildWithContext() injects "Relevant Knowledge:" section from RetrievalService results, capped at 8000 chars. Controlled by `aegis.rag.auto_retrieve` config.
- 2026-02-14: ProjectContextLoader reads `.aegis/context.md`, `.aegis/instructions.md`, `.cursorrules` from configured project path. Cached for 5 minutes. Max 50KB per file.
- 2026-02-14: KnowledgeBase Livewire component uses WithFileUploads trait. Documents stored in `storage/app/knowledge/`. Delete cascades: chunks → embeddings → file.
- 2026-02-14: `Embeddings::fake()` does NOT support `shouldReceive()` — it's not a Mockery mock. Test embedding failures by setting provider to 'disabled' instead.
- 2026-02-14: Phase 3 final test count: 452 passed (42 new tests added), 1 pre-existing failure (DesktopShellTest).

- 2026-02-14: Phase 4 Advanced Agent learnings:
- 2026-02-14: `Agent::fake()` takes `Closure|array` not a string. Use `PlanningAgent::fake(['response text'])` with array wrapping.
- 2026-02-14: `assertPrompted()` callback receives `AgentPrompt` object, not string. Access via `$prompt->prompt` for the user message.
- 2026-02-14: PlanningStep uses a dedicated `PlanningAgent` (lightweight SDK Agent with `Promptable` trait) calling `summary_provider`/`summary_model` for cost savings.
- 2026-02-14: ReflectionStep parses `APPROVED:` vs `NEEDS_REVISION:` prefixes from ReflectionAgent response. Returns `ReflectionResult` value object. Default OFF (`reflection_enabled=false`).
- 2026-02-14: `Embeddings::fake()` generates different random vectors per call — cosine similarity between two random 768-dim vectors is ~0.017. For memory-aware prompt tests, mock `EmbeddingService` to return deterministic vectors.
- 2026-02-14: `SystemPromptBuilder::buildWithContext()` now injects both "Past Conversations:" (memories via VectorStore, threshold > 0.3) and "Relevant Knowledge:" (RAG via RetrievalService).
- 2026-02-14: WebSearchTool uses DuckDuckGo HTML form POST (`html.duckduckgo.com/html/`) — no API key required. Parses `result__a` and `result__snippet` CSS classes.
- 2026-02-14: CodeExecutionTool writes code to temp files, executes via `Process::timeout()`, cleans up. Blocks dangerous patterns (exec, system, passthru, shell_exec, rm -rf). Supports PHP, bash, Python.
- 2026-02-14: CodeExecutionTool auto-discovers python via `which python3` then `which python` fallback.
- 2026-02-14: Phase 4 final test count: 484 passed (32 new tests added), 1 pre-existing failure (DesktopShellTest).
