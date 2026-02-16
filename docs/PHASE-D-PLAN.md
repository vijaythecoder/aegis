# Phase D: "Core Polish + Agentic Autonomy"

> Designed Feb 15, 2026. Planning document — no code yet.
> Predecessor: Memory System v2 (Phases A, B, C — all complete)

---

## What We Learned From OpenClaw

OpenClaw's power isn't memory (we already match/exceed them there). It's:

1. **Agentic autonomy** — AI plans, executes multi-step tasks, chains tools, adjusts on failure
2. **Proactive intelligence** — AI initiates actions (scheduled tasks, event-driven), not just responds
3. **Multi-channel control** — Users command AI from WhatsApp, Telegram, Slack seamlessly

OpenClaw's weaknesses (our advantages):
- Security nightmares (30K+ exposed instances, RCE exploits) → our Laravel security is solid
- Complex Docker setup → our NativePHP is one-click
- Malicious skills on ClawHub → our plugin signing/verification exists

---

## What We Already Have (More Than Expected)

The codebase audit revealed features beyond what we're actively using:

| Feature | Status | Gap |
|---------|--------|-----|
| Streaming responses | ✅ Working | Chat.php uses `$agent->stream()` + Livewire `$this->stream()` |
| 6 messaging adapters | ✅ Code exists | Telegram, Discord, WhatsApp, Slack, iMessage, Signal — need wiring/testing |
| 13 agent tools | ✅ Code exists | Browser, web search, file ops, shell, code execution, knowledge search, memory |
| MCP server | ✅ Code exists | AegisMcpServer, McpToolAdapter — need verification |
| Provider failover | ✅ Code exists | `ProviderManager::failover()` — NOT wired into AegisAgent |
| Planning + Reflection agents | ✅ Code exists | Standalone agents — NOT composed into autonomous loop |
| Plugin marketplace | ✅ Code exists | PluginManager, installer, sandboxing — need verification |
| RAG / knowledge base | ✅ Code exists | DocumentIngestion, Chunking, Retrieval + UI |
| Model capabilities | ✅ Code exists | Tracks vision, tools, streaming, structured output per model |

---

## Phase D Architecture

Two parallel tracks: **Polish** (quick wins) + **Autonomy** (big feature).

```
┌─────────────────────────────────────────────────────────────────┐
│                      PHASE D OVERVIEW                            │
│                                                                   │
│  Track 1: POLISH (Quick Wins)          Track 2: AUTONOMY         │
│  ─────────────────────────             ─────────────────────      │
│  D-1: Provider failover wire-up        D-4: Agent Loop Engine    │
│  D-2: Structured output extraction     D-5: Autonomous Planner   │
│  D-3: Reranking for search             D-6: Proactive Tasks      │
│                                        D-7: Agent Status UI      │
│                                                                   │
│  Effort: ~1 day                        Effort: ~3-4 days          │
│  Impact: Reliability + quality         Impact: Game-changer       │
└─────────────────────────────────────────────────────────────────┘
```

---

## Track 1: Core Polish (Quick Wins)

### D-1: Wire Provider Failover into AegisAgent

**Problem:** `ProviderManager::failover()` exists but AegisAgent doesn't use it. If Anthropic goes down, the agent fails.

**Solution:** Use Laravel AI SDK's built-in provider failover:

```php
// AegisAgent — already has provider() method
// Laravel AI SDK supports: $agent->prompt('...', provider: ['anthropic', 'openai'])
// Wire failover_chain from config into agent resolution
```

**Config already exists:**
```php
// config/aegis.php
'failover_chain' => ['anthropic', 'openai', 'gemini'],
```

**Changes:**
- `AegisAgent::provider()` → return failover chain array instead of single string
- OR: Wrap agent calls in `ProviderManager::failover()` in `Chat.php`

**Effort:** 30 min | **Impact:** Resilience when APIs go down

---

### D-2: Structured Output for Memory Extraction

**Problem:** `ExtractMemoriesJob` asks LLM for JSON via text prompt and manually parses it (lines 92-131). This is fragile — LLMs sometimes return malformed JSON, markdown-wrapped JSON, or extra explanation text.

**Solution:** Use Laravel AI SDK's `HasStructuredOutput` interface for guaranteed schema:

```php
class MemoryExtractorAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function schema(JsonSchema $schema): array
    {
        return [
            'memories' => $schema->array()->items(
                $schema->object([
                    'type' => $schema->enum(['fact', 'preference', 'note'])->required(),
                    'key' => $schema->string()->required(),
                    'value' => $schema->string()->required(),
                ])
            ),
        ];
    }
}
```

**Changes:**
- Create `MemoryExtractorAgent` with structured output
- Update `ExtractMemoriesJob` to use agent instead of raw Prism call
- Remove manual JSON parsing code

**Effort:** 1-2 hours | **Impact:** More reliable memory extraction, fewer silent failures

---

### D-3: Reranking for Better Search Results

**Problem:** `HybridSearchService` returns results ordered by BM25 + cosine similarity. This is good but not great — reranking with a dedicated model significantly improves relevance.

**Solution:** Laravel AI SDK has built-in `Reranking` support with Cohere/Jina/VoyageAI:

```php
use Laravel\Ai\Reranking;

// After hybrid search returns candidates
$results = $hybridSearch->search($query, $embedding, limit: 20);

$reranked = Reranking::of(
    $results->pluck('value')->all()
)->limit(10)->rerank($query);
```

**Changes:**
- Add reranking step to `HybridSearchService::search()`
- Config toggle: `aegis.memory.reranking_enabled` (default false, opt-in)
- Requires Cohere/Jina/VoyageAI API key

**Effort:** 2 hours | **Impact:** Significantly better memory recall relevance

---

## Track 2: Agentic Autonomy

### D-4: Agent Loop Engine

**Problem:** AegisAgent handles one prompt → one response. It can chain tools (MaxSteps=10) but cannot:
- Plan a multi-step approach before executing
- Reflect on its own output and retry if poor quality
- Autonomously decompose complex tasks

**OpenClaw equivalent:** Dynamic planning — AI decides tool sequence, executes, observes, adjusts.

**Solution:** Build an `AgentLoop` orchestrator that composes PlanningAgent + AegisAgent + ReflectionAgent:

```
User: "Research the latest PHP 8.4 features and create a summary document"

┌─ AgentLoop ──────────────────────────────────────────────┐
│                                                           │
│  Step 1: PLAN (PlanningAgent)                            │
│  ──────────────────────────                              │
│  Input: User request                                      │
│  Output: Numbered plan:                                   │
│    1. Search web for "PHP 8.4 new features"              │
│    2. Read top 3 results for details                      │
│    3. Organize findings by category                       │
│    4. Write summary to ~/Documents/php84-features.md     │
│                                                           │
│  Step 2: EXECUTE (AegisAgent — for each plan step)       │
│  ──────────────────────────                              │
│  Uses tools: web_search → browser_read → file_write      │
│  Collects intermediate results                            │
│                                                           │
│  Step 3: REFLECT (ReflectionAgent)                       │
│  ──────────────────────────                              │
│  Input: Original request + final output                   │
│  Output: "APPROVED" or "NEEDS_REVISION: missing X"       │
│                                                           │
│  Step 4: RETRY if NEEDS_REVISION (max 2 retries)        │
│  ──────────────────────────                              │
│  Feed revision feedback back into execution               │
│                                                           │
└──────────────────────────────────────────────────────────┘
```

**Architecture:**

```php
class AgentLoop
{
    public function __construct(
        private readonly PlanningAgent $planner,
        private readonly AegisAgent $executor,
        private readonly ReflectionAgent $reviewer,
    ) {}

    public function execute(string $prompt, int $conversationId): AgentLoopResult
    {
        // 1. Should we plan? (Simple questions skip planning)
        if (!$this->requiresPlanning($prompt)) {
            return $this->directExecution($prompt, $conversationId);
        }

        // 2. Generate plan
        $plan = $this->planner->prompt("Create a plan for: {$prompt}");

        // 3. Execute each step
        $results = [];
        foreach ($this->parseSteps($plan->text) as $step) {
            $result = $this->executor
                ->forConversation($conversationId)
                ->prompt($step);
            $results[] = $result;
        }

        // 4. Reflect on quality
        $combined = $this->combineResults($results);
        $review = $this->reviewer->prompt(
            "Query: {$prompt}\nResponse: {$combined}\nIs this complete and accurate?"
        );

        // 5. Retry if needed (max 2)
        if (str_starts_with($review->text, 'NEEDS_REVISION')) {
            return $this->retry($prompt, $review->text, $conversationId);
        }

        return new AgentLoopResult($combined, $plan->text, $review->text);
    }
}
```

**Planning detection** — not every message needs planning:
```php
private function requiresPlanning(string $prompt): bool
{
    // Simple: Short messages, questions, greetings → skip
    // Complex: Action verbs + multi-part requests → plan
    $actionVerbs = ['create', 'build', 'research', 'analyze', 'write',
                    'find', 'compare', 'summarize', 'organize', 'set up'];
    $hasAction = Str::contains(strtolower($prompt), $actionVerbs);
    $isComplex = str_word_count($prompt) > 15;
    return $hasAction && $isComplex;
}
```

**Changes:**
- Create `app/Agent/AgentLoop.php` — orchestrator
- Create `app/Agent/AgentLoopResult.php` — result value object
- Update PlanningAgent instructions — more detailed step generation
- Update ReflectionAgent instructions — structured quality evaluation
- Update `Chat.php` — use AgentLoop instead of direct AegisAgent for complex queries

**Effort:** 4-6 hours | **Impact:** AI goes from "chatbot" to "autonomous agent"

---

### D-5: Enhanced Planning & Reflection

**Problem:** Current PlanningAgent and ReflectionAgent have minimal instructions. They need to be task-aware and context-rich.

**Solution:** Upgrade both agents with:

**PlanningAgent v2:**
```
Given the user's request and available tools, create an execution plan.

Available tools: {list of current tools with descriptions}

Rules:
1. Each step must map to a specific tool or direct response
2. Steps should be atomic (one action each)
3. Include expected output format for each step
4. Mark steps that depend on previous results
5. Maximum 7 steps — if more needed, break into sub-tasks

Output format:
STEP 1: [action] using [tool] → expects [output type]
STEP 2: [action] using [tool] (needs: step 1 result) → expects [output type]
...
COMPLEXITY: [simple|moderate|complex]
```

**ReflectionAgent v2:**
```
Evaluate if the response fully addresses the user's request.

Criteria:
1. COMPLETENESS — Does it address all parts of the request?
2. ACCURACY — Are the facts/code/information correct?
3. ACTIONABILITY — Can the user act on this immediately?
4. TOOL_USAGE — Were the right tools used effectively?

Output exactly one of:
- APPROVED: [1-sentence reason]
- NEEDS_REVISION: [specific issue] | SUGGESTION: [what to do differently]
```

**Changes:**
- Update `PlanningAgent.php` — tool-aware instructions
- Update `ReflectionAgent.php` — structured evaluation criteria
- Both agents now receive context about available tools

**Effort:** 1-2 hours | **Impact:** Much smarter planning and quality control

---

### D-6: Proactive Intelligence (Scheduled Agent Tasks)

**Problem:** Aegis only responds to user messages. It never initiates. OpenClaw has cron jobs, webhooks, and a "heartbeat" mechanism for proactive actions.

**Examples of proactive intelligence:**
- "Good morning! You have 3 meetings today. Here's a prep summary."
- "The Hacker News post about PHP 8.4 you asked me to watch got 200 upvotes."
- "It's been a week since you worked on the Aegis project. Want to pick it up?"
- "Your API key for OpenAI expires in 7 days."

**Solution:** Create a `ProactiveTask` system:

```php
// Database: proactive_tasks table
Schema::create('proactive_tasks', function (Blueprint $table) {
    $table->id();
    $table->string('name');                    // "morning_briefing"
    $table->string('schedule');                // "0 8 * * 1-5" (cron expression)
    $table->string('prompt');                  // What to ask the agent
    $table->string('delivery_channel');        // "chat", "telegram", "notification"
    $table->boolean('is_active')->default(true);
    $table->timestamp('last_run_at')->nullable();
    $table->timestamp('next_run_at')->nullable();
    $table->timestamps();
});
```

```php
// app/Agent/ProactiveTaskRunner.php
class ProactiveTaskRunner
{
    public function __construct(
        private readonly AgentLoop $agentLoop,
        private readonly MessageRouter $router,
    ) {}

    public function runDueTasks(): int
    {
        $tasks = ProactiveTask::query()
            ->where('is_active', true)
            ->where('next_run_at', '<=', now())
            ->get();

        foreach ($tasks as $task) {
            $result = $this->agentLoop->execute($task->prompt, conversationId: null);
            $this->deliver($task, $result);
            $task->updateNextRun();
        }

        return $tasks->count();
    }
}
```

**Built-in proactive tasks:**
1. **Morning Briefing** — Summarize calendar, pending tasks, weather (configurable)
2. **Memory Digest** — Weekly summary of new things learned about the user
3. **Stale Conversation Nudge** — Remind about unfinished conversations
4. **API Key Expiration** — Warn when keys approach expiration

**Changes:**
- Create `ProactiveTask` model + migration + factory
- Create `ProactiveTaskRunner` service
- Create `aegis:proactive:run` artisan command
- Add to `routes/console.php` scheduler — run every minute
- Add UI in Settings to manage proactive tasks (enable/disable, schedule)
- Create `ProactiveTaskSeeder` with default tasks (disabled by default)

**Effort:** 4-6 hours | **Impact:** AI becomes a true proactive assistant, not just a chatbot

---

### D-7: Agent Status UI

**Problem:** When the agent is in a multi-step loop (planning → executing → reflecting), the user sees nothing — just "thinking." They don't know what's happening.

**Solution:** Real-time agent status display showing current step:

```
┌─────────────────────────────────────┐
│ 🧠 Planning approach...             │  ← Step 1
│ 🔍 Searching web for "PHP 8.4"...  │  ← Step 2 (tool in use)
│ 📄 Reading article...               │  ← Step 3
│ ✍️  Writing summary...              │  ← Step 4
│ ✅ Reviewing quality...             │  ← Step 5 (reflection)
└─────────────────────────────────────┘
```

**Implementation:** Use Livewire events + existing `AgentStatus.php` component:

```php
// In AgentLoop, dispatch events at each step:
$this->dispatch('agent-step', step: 'planning', detail: 'Analyzing request...');
$this->dispatch('agent-step', step: 'executing', detail: 'Using web_search tool...');
$this->dispatch('agent-step', step: 'reflecting', detail: 'Reviewing output quality...');
```

**Changes:**
- Update `AgentStatus.php` Livewire component — show step-by-step progress
- Update `AgentLoop` — dispatch Livewire events at each phase
- Update chat Blade template — render agent steps in real-time

**Effort:** 2-3 hours | **Impact:** Users see the AI "thinking," builds trust and excitement

---

## Implementation Order

```
Day 1: Quick Wins
├── D-1: Provider failover (30 min)
├── D-2: Structured output extraction (1-2 hours)
└── D-3: Reranking (2 hours)
    → Commit & push

Day 2: Agent Loop Foundation
├── D-4: AgentLoop engine (4-6 hours)
└── D-5: Enhanced Planning & Reflection (1-2 hours)
    → Commit & push

Day 3: Proactive Intelligence
├── D-6: Proactive tasks system (4-6 hours)
    → Commit & push

Day 4: UX & Polish
├── D-7: Agent status UI (2-3 hours)
├── Integration testing
└── Edge case handling
    → Commit & push
```

---

## Success Criteria

| Task | Metric |
|------|--------|
| D-1 | Agent auto-switches provider on API failure |
| D-2 | Memory extraction uses structured output, no manual JSON parsing |
| D-3 | Search results improve (reranking toggle in config) |
| D-4 | Complex requests (15+ words with action verbs) trigger plan→execute→reflect loop |
| D-5 | Plans reference specific tools; reflection catches incomplete responses |
| D-6 | Proactive tasks run on schedule, deliver via chat/Telegram |
| D-7 | User sees step-by-step progress during agent loop |

---

## Risk Mitigation

| Risk | Mitigation |
|------|------------|
| Agent loop takes too long | Planning detection filters simple queries. Max 2 retries. 120s timeout. |
| Structured output not supported by all models | Fallback to current text-based extraction for models without JSON mode |
| Reranking adds latency | Optional, off by default. Only for memory recall, not chat |
| Proactive tasks annoy users | All disabled by default. User enables manually. Delivery preferences. |
| Multi-step execution costs tokens | Use cheap model (Haiku) for planning/reflection. Only executor uses main model. |

---

## Context Window Budget (Updated)

```
System prompt:        ~10%  (was 15% — planning agent gets separate window)
Semantic memories:     10%
Episodic summaries:    10%
Agent loop context:     5%  (plan + reflection notes)
Conversation messages: 60%
Response reserve:       5%
```

---

## Dependencies

- **D-2** requires `laravel/ai` structured output support (confirmed available)
- **D-3** requires Cohere/Jina/VoyageAI API key (optional, off by default)
- **D-6** requires working queue driver (SQLite queue should work for NativePHP)
- All tasks are backwards-compatible — no breaking changes to existing memory system
