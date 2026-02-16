# Aegis Memory System v2 — "Better Than OpenClaw"

> Designed Feb 15, 2026. Planning document — no code yet.

---

## The Core Problem With OpenClaw (That We Solve)

OpenClaw's #1 user complaint: **the agent doesn't always search memory.** The system prompt says "mandatory recall step" but LLMs skip instructions 20-30% of the time. Casual messages like "hey" or "thanks" never trigger a search, so the agent feels amnesiac.

**Our innovation: Belt AND Suspenders.** Every memory operation happens in at least two independent ways. If one fails, the other catches it.

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        AEGIS MEMORY v2                                   │
│                  "Belt AND Suspenders" Architecture                       │
│                                                                           │
│   ┌─────────────────────┐    ┌──────────────────────┐                   │
│   │  BELT (Automatic)    │    │  SUSPENDERS (Agent)   │                   │
│   │                      │    │                       │                   │
│   │  Middleware injects   │    │  Agent has tools to   │                   │
│   │  memories into every  │    │  search deeper and    │                   │
│   │  prompt. Guaranteed.  │    │  store explicitly.    │                   │
│   │  No LLM decision.    │    │  Autonomous.          │                   │
│   └──────────┬───────────┘    └───────────┬───────────┘                   │
│              │                             │                               │
│              └─────────┬───────────────────┘                               │
│                        ▼                                                   │
│              ┌──────────────────┐                                         │
│              │  POST-RESPONSE    │                                         │
│              │  (Background)     │                                         │
│              │                   │                                         │
│              │  Queued job       │                                         │
│              │  extracts facts,  │                                         │
│              │  detects changes, │                                         │
│              │  updates profile  │                                         │
│              └──────────────────┘                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## The 5-Layer Memory Stack

### Layer 0: User Profile (Always-On Context)

**NEW. OpenClaw doesn't have this.**

Always-on, 150-300 tokens. Injected into EVERY system prompt. Contains: name, key preferences, active topics, timezone, patterns. Rebuilt periodically by background job from all other layers.

**Solves**: Cold start. "Hey" → Agent already knows who you are.
**OpenClaw**: Agent knows nothing until it decides to search.

Example:
```
## About This User
Name: Vijay. Timezone: America/Chicago.
Tech: Laravel 12, NativePHP, Pest, Tailwind v4. Prefers dark mode.
Current project: Aegis AI assistant (desktop app).
Recent topics: Memory system design, OpenClaw comparison.
Style: Direct, concise. Prefers plans before code.
```

### Layer 1: Working Memory (Current Conversation)

Storage: Laravel AI SDK `RemembersConversations` (EXISTS).
Budget: 60% of context window.

What's new:
- `ConversationSummarizer` WIRED IN (exists but unused today)
- Pre-extraction: before messages are pruned, queue fact extraction
- Context budget ENFORCED (not just configured)

### Layer 2: Episodic Memory (Past Conversations)

Storage: `conversations.summary` + vector embeddings (PARTIALLY EXISTS).
Budget: 10% of context window.

Every conversation gets a summary when closed/stale. Summaries are embedded and searchable. When middleware detects a reference to past work → injects summaries.

**Solves**: "like we discussed last week" → finds the actual conversation.
**OpenClaw**: Only has this if agent wrote good daily notes. Unreliable.

### Layer 3: Semantic Memory (Facts, Preferences, Knowledge)

Storage: `memories` table + `vector_embeddings` (EXISTS, broken wiring).
Budget: 10% of context window.

Three recall paths (redundancy):
- a) Middleware auto-recall (before every prompt)
- b) Agent `memory_recall` tool (for deeper searches)
- c) Agent `memory_store` tool (explicit "remember this")

Two extraction paths (redundancy):
- a) Background LLM extraction (after every response, queued)
- b) Agent `memory_store` tool (explicit save by agent)

Features OpenClaw lacks:
- Typed memories (fact/preference/note) with confidence scores
- Contradiction resolution (new fact with same key → archive old)
- Confidence decay (unused memories lose weight over time)
- Deduplication (upsert by type+key)

### Layer 4: Procedural Memory (Learned Behaviors)

Storage: NEW `procedures` table.
Budget: 5% of context window (loaded as system instructions).

Tracks:
- Tool-use patterns ("when asked to create files, check dir first")
- User corrections ("don't use var, use const")
- Workflow preferences ("always run tests before committing")

Neither OpenClaw nor any current system does this well. Differentiator for Phase C.

---

## Context Window Budget

```
┌─────────────────────────────────────────────────────────┐
│                   CONTEXT WINDOW (100%)                   │
│                                                           │
│  ┌─────────────────────────────────────────────────────┐ │
│  │ System Prompt + Layer 0 Profile           15%       │ │
│  ├─────────────────────────────────────────────────────┤ │
│  │ Layer 3: Semantic Memories (auto-injected) 10%      │ │
│  ├─────────────────────────────────────────────────────┤ │
│  │ Layer 2: Episodic Summaries               10%       │ │
│  ├─────────────────────────────────────────────────────┤ │
│  │ Layer 1: Conversation Messages            60%       │ │
│  ├─────────────────────────────────────────────────────┤ │
│  │ Response Reserve                           5%       │ │
│  └─────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────┘

Note: Layer 4 (Procedural) is included within the 15% system prompt budget.
The 60% for messages is the MAXIMUM — actual conversation messages.
Memory layers (10% + 10%) are CAPPED, not guaranteed — only injected
when relevant memories exist and score above threshold.
```

---

## The Complete Message Flow

```
USER: "Can you set up the auth module like we discussed?"
  │
  ▼
╔═══════════════════════════════════════════════════════════════════════╗
║  STEP 1: MEMORY MIDDLEWARE (Automatic — no LLM decision)            ║
║                                                                       ║
║  1a. Embed user message → query vector                               ║
║  1b. Search Layer 3 (semantic memories):                             ║
║      → "user prefers Laravel 12 + Pest + Sanctum for auth"          ║
║      → "user.preference: JWT over sessions"                          ║
║  1c. Search Layer 2 (episodic — conversation summaries):             ║
║      → "Feb 10: discussed auth module — decided on Sanctum"         ║
║  1d. Load Layer 0 (user profile — always present)                    ║
║  1e. Score check: if any result > 0.9 → flag as "highly relevant"   ║
║  1f. Inject into prompt as context sections                          ║
╚══════════════════════════════════╦════════════════════════════════════╝
                                   ▼
╔═══════════════════════════════════════════════════════════════════════╗
║  STEP 2: AGENT RESPONSE (Tool-based — agent decides)                ║
║                                                                       ║
║  Agent sees: user message + injected memories + tool list            ║
║  Agent might call:                                                    ║
║    memory_recall("Sanctum auth middleware configuration")            ║
║    → Gets detailed config from past conversation                     ║
║  Agent responds with full context.                                   ║
╚══════════════════════════════════╦════════════════════════════════════╝
                                   ▼
╔═══════════════════════════════════════════════════════════════════════╗
║  STEP 3: POST-RESPONSE (Background — queued, async)                 ║
║                                                                       ║
║  3a. LLM Fact Extraction (cheap/fast model)                          ║
║  3b. Contradiction Check against existing memories                   ║
║  3c. Embed & Store new memories                                      ║
║  3d. Conversation Summary Update (if threshold reached)              ║
║  3e. User Profile Refresh (if significant changes)                   ║
╚═══════════════════════════════════════════════════════════════════════╝
```

---

## OpenClaw vs Aegis v2 Comparison

| Problem | OpenClaw | Aegis v2 | Why Ours Is Better |
|---------|----------|----------|-------------------|
| Agent forgets to search | System prompt says "mandatory recall step" — LLM sometimes ignores | Middleware auto-injects. Plus tools for deeper search | Guaranteed recall |
| Cold start in new chat | Agent knows nothing until it searches | User Profile always injected (150-300 tokens) | Zero-latency personalization |
| Memory quality degrades | Unstructured Markdown accumulates | Typed memories + confidence + contradiction resolution + decay | Self-curating |
| "Like we discussed" | Only works if agent wrote good daily notes | Auto-summarized conversations, embedded, searchable | Complete history |
| Agent can't store | Uses generic writeFile — unstructured | Dedicated memory_store tool + background LLM extraction | Two write paths |
| Pre-compaction data loss | Silent flush turn — agent decides what to save | Background extraction on messages BEFORE pruning | Structured extraction |
| Stale memories | No aging at all | Confidence decay: -0.01/week unused | Self-cleaning |
| No proactive context | Agent never volunteers info | High-relevance flag triggers proactive hints | Anticipatory |

### What We Take From OpenClaw (Proven Patterns)

- Hybrid search (0.7 vector + 0.3 BM25) — already built
- Tool-based agent recall — already have MemoryRecallTool
- System prompt memory section — need to add
- Pre-compaction save — adapt as pre-extraction job
- Embedding cache by content hash — build for scale

### What We Do That OpenClaw Doesn't

- Middleware auto-injection (guaranteed recall)
- User Profile Layer 0 (cold start solved)
- Background LLM extraction (reliable fact capture)
- Typed memories + confidence (self-curating)
- Contradiction resolution (no stale/conflicting facts)
- Confidence decay (natural memory aging)
- Episodic memory auto-summaries (complete history)
- Proactive high-relevance hints (anticipatory)
- Procedural memory (learns HOW you like things done)

---

## Implementation Phases

### Phase A — "Make It Work" (cross-chat memory functional)

1. `SystemPromptBuilder` v2 — inject facts, memory instructions, user profile
2. `MemoryStoreTool` — agent can store memories
3. `MemoryMiddleware` — auto-inject relevant memories before every prompt
4. `UserProfileService` — build/update always-on user summary
5. LLM `FactExtractor` v2 — background job, replace regex
6. Wire everything into `AegisAgent` (middleware array, tool registry)

### Phase B — "Make It Reliable" (episodic + compaction + UI)

7. `ConversationSummaryService` — auto-summarize on close/stale
8. Pre-extraction hook — extract before messages are pruned
9. Contradiction resolution in `MemoryService`
10. Memory UI in Settings (Livewire component)
11. Confidence decay cron job

### Phase C — "Make It Exceptional" (differentiation)

12. Proactive high-relevance hints
13. Procedural memory (learned behaviors)
14. Memory consolidation job (deduplicate, merge, organize)
15. Temporal query resolution ("last week" → date range)
16. Memory export (Markdown + JSON)
17. Embedding cache by content hash
18. Vector indexing upgrade (sqlite-vec or whereVectorSimilarTo)

---

## Existing Code Inventory

| Component | Status | Action Needed |
|-----------|--------|---------------|
| `MemoryService` | ✅ Works | Extend: inject facts too, not just preferences |
| `HybridSearchService` | ✅ Works | Wire into middleware + query-time flow |
| `EmbeddingService` | ✅ Works | None |
| `VectorStore` | ⚠️ O(n) | Phase C: upgrade to indexed search |
| `FactExtractor` | ❌ Regex, never called | Replace with LLM-powered (Phase A) |
| `SystemPromptBuilder` | ⚠️ Partial | v2: facts + episodic + procedural (Phase A) |
| `ConversationSummarizer` | ⚠️ Exists, unused | Wire into flow (Phase B) |
| `MemoryRecallTool` | ✅ Works | Add system prompt instructions (Phase A) |
| `AegisAgent.middleware()` | ❌ Returns [] | Add MemoryMiddleware (Phase A) |
| `AegisConversationStore` | ✅ Works | None |
