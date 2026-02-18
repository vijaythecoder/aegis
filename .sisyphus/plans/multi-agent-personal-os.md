# Multi-Agent Personal OS for Aegis

## TL;DR

> **Quick Summary**: Transform Aegis from a single-agent AI assistant into a multi-agent personal life operating system. Users create specialized AI agents (personas) conversationally or via UI, equip them with reusable skills (knowledge packages), organize work into projects and tasks, and get proactive check-ins from their AI chief of staff.
> 
> **Deliverables**:
> - Agent system: DB-backed user-created agents with separate conversation threads
> - Skills system: Composable knowledge packages (built-in, marketplace, user-created)
> - Projects & Tasks: Auto-created by AI, tracked on a dashboard, executed by agents or humans
> - DynamicAgent class: Instantiates agents from DB records with per-agent tools/skills/middleware
> - Redesigned sidebar: Agents section, Conversations section, Projects section
> - Agent CRUD UI: Create, edit, delete agents with persona, skills, and tool assignment
> - Skill management UI: Library, create custom, assign to agents
> - Project dashboard: Full-page view with task list, progress tracking, project knowledge
> - Conversational agent creation: "I want a fitness coach" → Aegis creates the agent
> - Task execution pipeline: Background (queued), collaborative (agent thread), human-tracked (reminders)
> - Project knowledge: Layered knowledge sharing (global → project → agent)
> - Proactive check-ins: Scheduled project review, deadline reminders, cross-project awareness
> - @mention routing: "@FitCoach" in chat routes to that agent's thread
> - Agent-to-agent delegation: Task-based handoff with depth limiting
> 
> **Estimated Effort**: XL (35+ tasks across 10 waves)
> **Parallel Execution**: YES — 3-5 tasks per wave
> **Critical Path**: Models → DynamicAgent → Sidebar+UI → Tools → Task Execution → Proactive

---

## Context

### Original Request
User wants to design a multi-agent system for Aegis where users create multiple agents with agentic handoff, project/task tracking, and knowledge sharing — inspired by OpenClaw's multi-agent patterns but adapted for a personal life OS (not just work).

### Interview Summary
**Key Discussions**:
- **Agent creation**: User-facing UI + conversational creation. AI creates, humans refine.
- **Skills concept**: Agents = WHO (persona), Skills = WHAT THEY KNOW (knowledge packages), Tools = WHAT THEY CAN DO (actions). Composable and reusable.
- **Projects**: AI auto-creates when detecting multi-step goals. Flat structure with optional categories. Agent-agnostic (no single agent "owns" a project).
- **Tasks**: Three types — agent-executed (background), human-tracked (reminders), collaborative (agent thread).
- **Knowledge**: Layered — Global (existing MemoryService), Project (new), Agent (implicit per-conversation).
- **Agent threads**: Each user agent gets its own conversation in sidebar. Separate from main Aegis chat.
- **Dashboard**: Full-page view when clicking a project. Visual project board with task list and progress.
- **Proactive**: Scheduled check-ins. Aegis reviews open projects and nudges.
- **Not rigid**: Should be usable by everyone. Progressive complexity — simple users just chat, power users create agents and projects.
- **Personal life**: Taxes, kids, health, home — not just work.
- **Handoff**: All three modes — task-based (agent creates task), @mentions, orchestrator auto-routing.

**Research Findings**:
- **OpenClaw** (206K stars): Uses workspace files for agent definition, `sessions_spawn` for sub-agents, skills via SKILL.md markdown files. Multi-agent routing is per-channel/user isolation. No formal "task" object.
- **Laravel AI SDK**: No built-in multi-agent. Agent-as-Tool for delegation. AnonymousAgent is stateless (NOT suitable for persistent user agents). HasStructuredOutput for typed data passing. Custom `Conversational::messages()` for shared context.
- **Current Aegis**: 7 agent classes (all internal/utility), ToolRegistry with auto-discovery (11 tools), InjectMemoryContext middleware, AgentLoop (plan/execute/reflect), AegisConversationStore, ProviderManager with failover.

### Metis Review
**Identified Gaps** (addressed):
- AnonymousAgent is stateless — INVALID for user agents → Use DynamicAgent class wrapping Eloquent model
- SystemPromptBuilder must be extended carefully — HIGH risk → Add renderSkillsSection() as NEW method, don't restructure
- ToolRegistry is global singleton → Filter per-agent in DynamicAgent::tools(), NOT in registry
- No user_id needed — single-user desktop app → All new tables omit user_id
- Context window overflow risk → Hard limits: max 10 agents, max 5 skills/agent, max 3000 tokens/skill
- Existing conversations have no agent_id → Nullable column, existing conversations treated as default agent
- "AI auto-creates projects" scope creep → Agent uses ProjectTool to create, shows summary. User can modify.
- 314 existing tests must pass after every wave → Full test suite verification per wave
- Skills ≠ Plugins — Skills inject knowledge text, plugins execute PHP code. Distinct systems.

---

## Work Objectives

### Core Objective
Transform Aegis into a multi-agent personal life OS where users create specialized AI personas equipped with reusable knowledge skills, organize life into projects with trackable tasks, and get proactive AI-driven coordination — all through natural conversation.

### Concrete Deliverables
- `app/Models/Agent.php` — Eloquent model for user-created agents
- `app/Models/Skill.php` — Eloquent model for knowledge packages
- `app/Models/Project.php` — Eloquent model for projects
- `app/Models/Task.php` — Eloquent model for tasks
- `app/Models/ProjectKnowledge.php` — Eloquent model for project-scoped knowledge
- `app/Agent/DynamicAgent.php` — SDK agent class instantiated from DB records
- `app/Agent/AgentRegistry.php` — Service to resolve agents by ID/slug
- `app/Agent/ProjectReviewAgent.php` — System agent for proactive project check-ins
- `app/Tools/ProjectTool.php` — Tool for creating/managing projects
- `app/Tools/TaskTool.php` — Tool for creating/managing tasks
- `app/Tools/AgentCreatorTool.php` — Tool for conversational agent creation
- `app/Agent/Middleware/InjectProjectContext.php` — Middleware for project knowledge injection
- `app/Services/ProjectKnowledgeService.php` — Service for project-scoped knowledge CRUD
- `app/Services/ContextBudgetCalculator.php` — Token budget calculator
- `app/Jobs/ExecuteAgentTaskJob.php` — Background task execution via queue
- `app/Livewire/AgentSettings.php` — Agent CRUD UI
- `app/Livewire/SkillSettings.php` — Skill management UI
- `app/Livewire/ProjectDashboard.php` — Project dashboard with task list
- Updated `app/Livewire/ConversationSidebar.php` — Agents, conversations, projects sections
- Updated `app/Livewire/Chat.php` — Agent-aware conversation handling
- Updated `app/Agent/SystemPromptBuilder.php` — Skills injection section
- Database migrations for all new tables and schema changes
- Factories and seeders for all new models
- Built-in skills (Research, Writing, Analysis, Scheduling, Finance, Health, Education)
- Pest feature tests for all new functionality

### Definition of Done
- [ ] `php artisan test --compact` → ALL tests pass (existing 314+ and new tests)
- [ ] User can create an agent through Settings UI with name, persona, skills, tools
- [ ] User can create an agent conversationally ("I want a fitness coach")
- [ ] Each user agent has its own conversation thread in sidebar
- [ ] Skills are composable knowledge packages that inject into agent system prompts
- [ ] Projects and tasks are created by AI when appropriate, shown on dashboard
- [ ] Task execution works: background (queued), collaborative (agent thread), human-tracked
- [ ] Project knowledge flows: task outputs feed into project-scoped knowledge
- [ ] Proactive check-ins run on schedule and nudge about deadlines/stalled tasks
- [ ] @mention routing works: "@FitCoach do this" routes to FitCoach's thread
- [ ] Delegation works: Agent A can create a task for Agent B with depth limiting

### Must Have
- DynamicAgent class that does NOT modify AegisAgent (composition, not modification)
- Backward compatibility: all existing AegisAgent functionality unchanged
- Per-agent tool filtering (agent_tools pivot, filtered in DynamicAgent::tools())
- Per-agent skill injection (agent_skills pivot, injected via SystemPromptBuilder)
- Nullable agent_id on conversations (existing conversations remain null)
- Hard limits: max 10 agents, max 5 skills per agent, max 3000 tokens per skill content
- Full test suite passing after every wave
- All new models with factories and seeders
- SQLite-compatible (no MySQL/Postgres-only features)

### Must NOT Have (Guardrails)
- DO NOT modify AegisAgent class signatures or remove existing public methods
- DO NOT modify ToolRegistry global behavior — filter in DynamicAgent, not registry
- DO NOT create a new marketplace system — skills use existing MarketplacePlugin with type discriminator
- DO NOT add user_id columns — single-user desktop app
- DO NOT allow multi-agent responses in a single conversation — one agent per conversation
- DO NOT create executable code skills — skills are TEXT content (markdown/instructions), NOT PHP
- DO NOT break existing messaging channel routing — extend, don't replace
- DO NOT add new PHP composer dependencies without explicit approval
- DO NOT create criteria requiring human manual testing — all verification must be agent-executable
- DO NOT over-abstract — avoid premature abstraction patterns. Keep it simple.

---

## Verification Strategy

> **ZERO HUMAN INTERVENTION** — ALL verification is agent-executed. No exceptions.

### Test Decision
- **Infrastructure exists**: YES — Pest 3 with 84 test files, 314+ tests
- **Automated tests**: YES (Tests-after) — Each task creates tests, run at end of wave
- **Framework**: Pest 3 via `php artisan test --compact`
- **Critical**: `php artisan test --compact` (FULL suite) must pass at end of EVERY wave

### QA Policy
Every task MUST include agent-executed QA scenarios.
Evidence saved to `.sisyphus/evidence/task-{N}-{scenario-slug}.{ext}`.

| Deliverable Type | Verification Tool | Method |
|------------------|-------------------|--------|
| Models/Migrations | Bash (tinker) | Create via factory, verify relationships, query |
| Livewire UI | Playwright (playwright skill) | Navigate, interact, assert DOM, screenshot |
| Agent classes | Bash (pest) | Unit tests with Agent::fake() |
| Tools | Bash (pest) | Feature tests with mocked agent responses |
| Background jobs | Bash (pest) | Queue::fake(), assert dispatched |

---

## Execution Strategy

### Parallel Execution Waves

```
Wave 1 (Foundation — Data Models & Migrations):
├── Task 1: Agent + Skill models, migrations, factories, relationships [quick]
├── Task 2: Project + Task + ProjectKnowledge models, migrations, factories [quick]
├── Task 3: Pivot tables + schema updates (agent_skills, agent_tools, agent_id on conversations/token_usages) [quick]
├── Task 4: Built-in skills seeder + default agent seeder [quick]
└── Task 5: Wave 1 test verification (full suite) [quick]

Wave 2 (Core Agent Infrastructure):
├── Task 6: DynamicAgent class (Agent, Conversational, HasMiddleware, HasTools) [deep]
├── Task 7: AgentRegistry service (resolve by ID/slug, instantiate DynamicAgent) [unspecified-high]
├── Task 8: SystemPromptBuilder: renderSkillsSection() + skill injection [unspecified-high]
└── Task 9: Wave 2 test verification [quick]

Wave 3 (Agent & Skill UI):
├── Task 10: ConversationSidebar redesign (Agents/Conversations/Projects sections) [visual-engineering]
├── Task 11: Agent management pages (list, create, edit, delete) [visual-engineering]
├── Task 12: Skill management in Settings (library, view, create custom) [visual-engineering]
├── Task 13: Chat component: agent-aware (persona avatar, agent thread routing) [visual-engineering]
└── Task 14: Wave 3 test verification [quick]

Wave 4 (Project Dashboard & Agent Tools):
├── Task 15: Project dashboard page (full-page view, tasks, progress, knowledge) [visual-engineering]
├── Task 16: ProjectTool for AegisAgent (create, update, list, archive) [unspecified-high]
├── Task 17: TaskTool for AegisAgent (create, update, complete, list) [unspecified-high]
├── Task 18: AegisAgent system prompt updates (projects, agents, skills awareness) [unspecified-high]
└── Task 19: Wave 4 test verification [quick]

Wave 5 (Task Execution & Knowledge Flow):
├── Task 20: ExecuteAgentTaskJob (background task execution via queue) [deep]
├── Task 21: Collaborative task execution (messages in agent thread) [deep]
├── Task 22: Project knowledge service + InjectProjectContext middleware [unspecified-high]
└── Task 23: Wave 5 test verification [quick]

Wave 6 (Conversational Intelligence):
├── Task 24: AgentCreatorTool (create agents from natural language) [deep]
├── Task 25: Context window budget calculator (warn on overflow) [unspecified-high]
├── Task 26: Skill suggestion when creating agents [unspecified-high]
└── Task 27: Wave 6 test verification [quick]

Wave 7 (Delegation & Handoff):
├── Task 28: Task-based delegation (agent creates task for another agent) [deep]
├── Task 29: @mention routing (parse @AgentName, route to thread) [unspecified-high]
├── Task 30: Delegation depth limiting + circular prevention [unspecified-high]
└── Task 31: Wave 7 test verification [quick]

Wave 8 (Proactive Chief of Staff):
├── Task 32: ProjectReviewAgent (scan projects, generate nudges) [deep]
├── Task 33: Scheduled check-in system (extend ProactiveTask, cron) [unspecified-high]
├── Task 34: Deadline reminders + cross-project awareness [unspecified-high]
└── Task 35: Wave 8 test verification [quick]

Wave 9 (Advanced Features & Polish):
├── Task 36: Project templates (pre-defined task sets) [unspecified-high]
├── Task 37: Messaging channel routing (default agent for platforms) [unspecified-high]
├── Task 38: AI-generated skills (from conversation context) [deep]
└── Task 39: Wave 9 test verification [quick]

Wave FINAL (Verification — after ALL implementation):
├── Task F1: Plan compliance audit [oracle]
├── Task F2: Code quality review [unspecified-high]
├── Task F3: Real QA (Playwright UI + curl API + tinker models) [unspecified-high]
└── Task F4: Scope fidelity check [deep]

Critical Path: T1→T3→T6→T7→T10→T13→T16→T20→T28→T32→F1-F4
Parallel Speedup: ~65% faster than sequential
Max Concurrent: 4 (Waves 1, 3, 4)
```

### Dependency Matrix

| Task | Depends On | Blocks | Wave |
|------|------------|--------|------|
| 1 | — | 3, 4, 6, 7, 8, 11, 12 | 1 |
| 2 | — | 4, 15, 16, 17, 20 | 1 |
| 3 | 1 | 6, 7, 10, 13 | 1 |
| 4 | 1, 2 | 8, 12 | 1 |
| 5 | 1-4 | 6-9 | 1 |
| 6 | 1, 3, 5 | 7, 8, 13, 20, 21, 24 | 2 |
| 7 | 1, 3, 6 | 11, 13, 24, 28 | 2 |
| 8 | 1, 4, 6 | 12, 25 | 2 |
| 9 | 6-8 | 10-14 | 2 |
| 10 | 3, 9 | 13 | 3 |
| 11 | 1, 7, 9 | — | 3 |
| 12 | 4, 8, 9 | — | 3 |
| 13 | 6, 7, 10, 9 | 21, 29 | 3 |
| 14 | 10-13 | 15-19 | 3 |
| 15 | 2, 14 | — | 4 |
| 16 | 2, 14 | 18, 20 | 4 |
| 17 | 2, 14 | 18, 20, 28 | 4 |
| 18 | 16, 17 | 24 | 4 |
| 19 | 15-18 | 20-23 | 4 |
| 20 | 6, 16, 17, 19 | 28, 32 | 5 |
| 21 | 6, 13, 19 | — | 5 |
| 22 | 2, 6, 19 | 32 | 5 |
| 23 | 20-22 | 24-27 | 5 |
| 24 | 6, 7, 18, 23 | — | 6 |
| 25 | 8, 23 | — | 6 |
| 26 | 4, 7, 23 | — | 6 |
| 27 | 24-26 | 28-31 | 6 |
| 28 | 7, 17, 20, 27 | 30 | 7 |
| 29 | 7, 13, 27 | — | 7 |
| 30 | 28 | — | 7 |
| 31 | 28-30 | 32-35 | 7 |
| 32 | 20, 22, 31 | 33, 34 | 8 |
| 33 | 32 | — | 8 |
| 34 | 32, 33 | — | 8 |
| 35 | 32-34 | 36-39 | 8 |
| 36 | 2, 35 | — | 9 |
| 37 | 7, 35 | — | 9 |
| 38 | 4, 24, 35 | — | 9 |
| 39 | 36-38 | F1-F4 | 9 |

### Agent Dispatch Summary

| Wave | # Parallel | Tasks → Agent Category |
|------|------------|----------------------|
| 1 | **4** | T1-T4 → `quick` |
| 2 | **3** | T6 → `deep`, T7-T8 → `unspecified-high` |
| 3 | **4** | T10-T13 → `visual-engineering` |
| 4 | **4** | T15 → `visual-engineering`, T16-T18 → `unspecified-high` |
| 5 | **3** | T20-T21 → `deep`, T22 → `unspecified-high` |
| 6 | **3** | T24 → `deep`, T25-T26 → `unspecified-high` |
| 7 | **3** | T28 → `deep`, T29-T30 → `unspecified-high` |
| 8 | **3** | T32 → `deep`, T33-T34 → `unspecified-high` |
| 9 | **3** | T36-T37 → `unspecified-high`, T38 → `deep` |
| FINAL | **4** | F1 → `oracle`, F2-F3 → `unspecified-high`, F4 → `deep` |

---

## TODOs

> Implementation + Test = ONE Task. Never separate.
> EVERY task MUST have: Recommended Agent Profile + Parallelization info + QA Scenarios.

### Wave 1: Foundation — Data Models & Migrations

- [ ] 1. Agent + Skill models, migrations, factories, relationships

  **What to do**:
  - Create `Agent` model via `php artisan make:model Agent -mf`: `name` (string), `slug` (string, unique), `avatar` (string nullable, emoji or URL), `persona` (text — personality/style instructions), `provider` (string nullable), `model` (string nullable), `settings` (JSON nullable — proactive_schedule, etc.), `is_active` (boolean default true). Timestamps.
  - Create `Skill` model via `php artisan make:model Skill -mf`: `name` (string), `slug` (string, unique), `description` (text), `instructions` (text — the actual knowledge content, max 3000 tokens enforced via validation), `category` (string nullable — finance, health, education, etc.), `source` (string — built_in, marketplace, user_created, ai_generated), `version` (string default '1.0'), `is_active` (boolean default true), `metadata` (JSON nullable). Timestamps.
  - Add relationships: `Agent` hasMany `Conversation`, `Agent` belongsToMany `Skill` (via `agent_skills`), `Agent` has JSON `allowed_tools` attribute (or pivot, decided in Task 3).
  - Add `casts()` method on both models: `settings` → `array`, `metadata` → `array`, `is_active` → `boolean`.
  - Create factories: `AgentFactory` with realistic faker data, states for `inactive()`, `withPersona()`. `SkillFactory` with states for `builtIn()`, `userCreated()`, `marketplace()`.
  - Add validation: `Skill.instructions` max 15000 characters (~3000 tokens). `Agent.name` max 50 chars. `Agent.slug` unique.

  **Must NOT do**:
  - Do NOT add `user_id` columns (single-user desktop app)
  - Do NOT create a `SkillPlugin` or connect to the Plugin system yet
  - Do NOT modify any existing models

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: Standard model/migration/factory creation — well-understood Laravel patterns
  - **Skills**: [`pest-testing`]
    - `pest-testing`: Need to create tests for model relationships and validation
  - **Skills Evaluated but Omitted**:
    - `livewire-development`: No UI in this task
    - `developing-with-ai-sdk`: No agent logic yet

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Tasks 2, 3, 4)
  - **Blocks**: Tasks 3, 4, 6, 7, 8, 11, 12
  - **Blocked By**: None (can start immediately)

  **References**:

  **Pattern References**:
  - `app/Models/Conversation.php` — Follow existing model pattern: casts(), relationships, no user_id
  - `app/Models/Memory.php` — Example of model with enum-like `type` field and text content
  - `database/migrations/` — Follow existing migration naming convention and style

  **API/Type References**:
  - `app/Enums/MemoryType.php` — Pattern for creating source enum if needed

  **Test References**:
  - `tests/Feature/MemoryServiceTest.php` — Pattern for testing model CRUD and relationships
  - `database/factories/ConversationFactory.php` — Factory pattern used in this project

  **WHY Each Reference Matters**:
  - `Conversation.php` shows the exact model style: no user_id, uses casts(), has factory. Copy this pattern exactly.
  - `ConversationFactory.php` shows faker usage patterns. New factories should match.

  **Acceptance Criteria**:
  - [ ] `php artisan migrate --force` succeeds (agents and skills tables created)
  - [ ] `php artisan tinker --execute="App\Models\Agent::factory()->create(['name' => 'Test'])"` → creates agent
  - [ ] `php artisan tinker --execute="App\Models\Skill::factory()->create(['name' => 'Test'])"` → creates skill
  - [ ] `php artisan test --compact --filter=AgentModelTest` → PASS
  - [ ] `php artisan test --compact --filter=SkillModelTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Create agent with factory and verify attributes
    Tool: Bash (tinker)
    Preconditions: Fresh migration
    Steps:
      1. Run: php artisan tinker --execute="$a = App\Models\Agent::factory()->create(); echo json_encode(['name'=>$a->name, 'slug'=>$a->slug, 'is_active'=>$a->is_active])"
      2. Assert output contains valid JSON with name (string), slug (string), is_active (true)
      3. Run: php artisan tinker --execute="echo App\Models\Agent::count()"
      4. Assert output is "1" or greater
    Expected Result: Agent created with all attributes, queryable
    Evidence: .sisyphus/evidence/task-1-agent-factory.txt

  Scenario: Skill instructions max length validation
    Tool: Bash (tinker)
    Preconditions: Skill model exists
    Steps:
      1. Run: php artisan tinker --execute="try { App\Models\Skill::factory()->create(['instructions' => str_repeat('x', 16000)]); echo 'CREATED'; } catch (\Exception $e) { echo 'VALIDATION_FAILED: ' . $e->getMessage(); }"
      2. Assert output contains "VALIDATION_FAILED" or model validates at application layer
    Expected Result: Skill with >15000 char instructions is rejected
    Evidence: .sisyphus/evidence/task-1-skill-validation.txt
  ```

  **Commit**: YES (groups with Wave 1)
  - Message: `feat(agents): add agent and skill models with migrations and factories`
  - Files: `app/Models/Agent.php`, `app/Models/Skill.php`, `database/migrations/*_create_agents_table.php`, `database/migrations/*_create_skills_table.php`, `database/factories/AgentFactory.php`, `database/factories/SkillFactory.php`, `tests/Feature/AgentModelTest.php`, `tests/Feature/SkillModelTest.php`

- [ ] 2. Project + Task + ProjectKnowledge models, migrations, factories

  **What to do**:
  - Create `Project` model via `php artisan make:model Project -mf`: `title` (string), `description` (text nullable), `status` (string — active, paused, completed, archived), `category` (string nullable — AI-suggested: finance, health, family, home, learning, etc.), `deadline` (datetime nullable), `metadata` (JSON nullable). Timestamps.
  - Create `Task` model via `php artisan make:model Task -mf`: `project_id` (foreignId nullable — standalone tasks possible), `title` (string), `description` (text nullable), `status` (string — pending, in_progress, waiting, completed, cancelled), `assigned_type` (string — agent, system, user), `assigned_id` (unsignedBigInteger nullable — agent_id when assigned_type=agent), `priority` (string — low, medium, high, default medium), `deadline` (datetime nullable), `parent_task_id` (unsignedBigInteger nullable — one-level subtasks), `output` (text nullable — deliverable when completed), `completed_at` (datetime nullable). Timestamps.
  - Create `ProjectKnowledge` model via `php artisan make:model ProjectKnowledge -m`: `project_id` (foreignId), `task_id` (foreignId nullable), `key` (string), `value` (text), `type` (string — finding, decision, artifact, note). Timestamps.
  - Relationships: `Project` hasMany `Task`, `Project` hasMany `ProjectKnowledge`. `Task` belongsTo `Project` (nullable), `Task` belongsTo `Agent` (via assigned_id when assigned_type=agent), `Task` hasMany `Task` (subtasks via parent_task_id), `Task` belongsTo `Task` (parent).
  - Factories: `ProjectFactory` with states `active()`, `completed()`, `archived()`. `TaskFactory` with states `pending()`, `completed()`, `assignedToAgent()`, `assignedToUser()`.
  - Add scopes: `Project::active()`, `Task::pending()`, `Task::forAgent($agentId)`.

  **Must NOT do**:
  - Do NOT add `user_id` columns
  - Do NOT create Livewire components yet
  - Do NOT create project/task tools yet

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Tasks 1, 3, 4)
  - **Blocks**: Tasks 4, 15, 16, 17, 20
  - **Blocked By**: None

  **References**:

  **Pattern References**:
  - `app/Models/Conversation.php` — Model pattern with scopes and relationships
  - `app/Models/Message.php` — Model with foreign key relationships and casts
  - `app/Models/PendingAction.php` — Model with status enum pattern (pending/approved/rejected/expired)

  **Test References**:
  - `tests/Feature/MemoryServiceTest.php` — CRUD testing patterns
  - `database/factories/MessageFactory.php` — Factory with foreign key relationships

  **WHY Each Reference Matters**:
  - `PendingAction.php` has the exact status lifecycle pattern (pending → in_progress → completed). Follow this for Task model.
  - `Message.php` shows belongsTo relationship with Conversation — same pattern for Task → Project.

  **Acceptance Criteria**:
  - [ ] Migrations create `projects`, `tasks`, `project_knowledge` tables
  - [ ] `Project::factory()->create()` works with all states
  - [ ] `Task::factory()->create()` works, including with project relationship
  - [ ] `ProjectKnowledge` can be created with project association
  - [ ] `php artisan test --compact --filter=ProjectModelTest` → PASS
  - [ ] `php artisan test --compact --filter=TaskModelTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Create project with tasks and verify relationships
    Tool: Bash (tinker)
    Preconditions: Fresh migration
    Steps:
      1. Run: php artisan tinker --execute="$p = App\Models\Project::factory()->create(['title' => 'Tax Prep']); $t = App\Models\Task::factory()->create(['project_id' => $p->id, 'title' => 'Gather W-2']); echo $p->tasks->count() . ' tasks'"
      2. Assert output: "1 tasks"
    Expected Result: Project has tasks relationship working
    Evidence: .sisyphus/evidence/task-2-project-tasks.txt

  Scenario: Task subtask relationship
    Tool: Bash (tinker)
    Steps:
      1. Create parent task, then child task with parent_task_id set
      2. Assert parent->subtasks()->count() === 1
      3. Assert child->parent->id === parent->id
    Expected Result: One-level subtask nesting works
    Evidence: .sisyphus/evidence/task-2-subtasks.txt
  ```

  **Commit**: YES (groups with Wave 1)
  - Message: `feat(projects): add project, task, and project knowledge models`
  - Files: `app/Models/Project.php`, `app/Models/Task.php`, `app/Models/ProjectKnowledge.php`, migrations, factories, tests

- [ ] 3. Pivot tables + schema updates

  **What to do**:
  - Create migration for `agent_skills` pivot: `agent_id` (foreignId, constrained, cascadeOnDelete), `skill_id` (foreignId, constrained, cascadeOnDelete). Unique composite index.
  - Create migration for `agent_tools` table: `agent_id` (foreignId, constrained, cascadeOnDelete), `tool_name` (string). Unique composite index. This stores which tools each agent can use — tool_name matches the `name()` from Tool classes.
  - Create migration to add `agent_id` (foreignId nullable, constrained, nullOnDelete) to `conversations` table. Existing conversations remain null (= default Aegis agent).
  - Create migration to add `agent_id` (foreignId nullable, constrained, nullOnDelete) to `token_usages` table (if it exists — check first).
  - Add `belongsToMany` relationships on Agent model: `skills()`, and a method `allowedToolNames()` that returns the tool_name values.
  - Add `belongsTo('agent')` on Conversation model.
  - Test: existing conversations still load with null agent_id.

  **Must NOT do**:
  - Do NOT modify ToolRegistry
  - Do NOT modify AegisAgent
  - Do NOT set agent_id on existing conversations

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES (but needs Task 1 for Agent model)
  - **Parallel Group**: Wave 1 (runs after Task 1 model files exist)
  - **Blocks**: Tasks 6, 7, 10, 13
  - **Blocked By**: Task 1

  **References**:

  **Pattern References**:
  - `app/Models/Conversation.php` — Will be modified to add agent() relationship
  - `database/migrations/` — Existing migration patterns for ALTER TABLE ADD COLUMN

  **Test References**:
  - `tests/Feature/IntegrationTest.php` — Tests that create conversations — verify they still work with null agent_id

  **WHY Each Reference Matters**:
  - Must verify that ConversationService::create() and existing conversation tests pass unchanged after adding nullable agent_id.

  **Acceptance Criteria**:
  - [ ] `agent_skills` and `agent_tools` pivot tables exist
  - [ ] `conversations.agent_id` column exists and is nullable
  - [ ] Existing conversations have `agent_id = null`
  - [ ] `Agent::factory()->create()->skills()->attach(Skill::factory()->create())` works
  - [ ] `php artisan test --compact --filter=IntegrationTest` → PASS (existing conversations unbroken)

  **QA Scenarios**:

  ```
  Scenario: Existing conversations unaffected by schema change
    Tool: Bash (tinker)
    Steps:
      1. Create a conversation via Conversation::factory()->create()
      2. Assert $conversation->agent_id === null
      3. Assert $conversation->agent === null (belongsTo returns null)
      4. Assert Conversation::all() works without errors
    Expected Result: Null agent_id doesn't break existing functionality
    Evidence: .sisyphus/evidence/task-3-backward-compat.txt

  Scenario: Agent-skill pivot works
    Tool: Bash (tinker)
    Steps:
      1. Create agent, create 2 skills, attach both
      2. Assert $agent->skills()->count() === 2
      3. Detach one, assert count === 1
    Expected Result: Many-to-many relationship works correctly
    Evidence: .sisyphus/evidence/task-3-agent-skills-pivot.txt
  ```

  **Commit**: YES (groups with Wave 1)

- [ ] 4. Built-in skills seeder + default agent seeder

  **What to do**:
  - Create `SkillSeeder`: seed 7 built-in skills with source='built_in':
    - **Research** (category: productivity): Instructions for effective web searching, source evaluation, synthesizing findings, comparing options
    - **Writing** (category: productivity): Instructions for drafting, editing, tone adjustment, structured writing
    - **Analysis** (category: productivity): Instructions for data analysis, comparison frameworks, decision matrices
    - **Scheduling** (category: productivity): Instructions for time management, deadline tracking, reminder patterns
    - **Finance** (category: finance): Instructions for budgeting, tax basics, financial planning concepts
    - **Health & Fitness** (category: health): Instructions for workout programming, nutrition basics, wellness tracking
    - **Education** (category: education): Instructions for tutoring, learning strategies, study plans, age-appropriate teaching
  - Each skill should have 500-1500 tokens of practical, actionable instructions (not generic).
  - Create `DefaultAgentSeeder`: Creates a single "Aegis" agent record representing the existing default assistant. `name: 'Aegis'`, `slug: 'aegis'`, `avatar: '🛡️'`, `persona: 'You are Aegis, a helpful, safe, and accurate AI assistant.'`, `is_active: true`. Attach ALL skills. This ensures existing conversations (agent_id=null) map to a known default.
  - Register seeders in `DatabaseSeeder`.
  - Make seeders idempotent: use `firstOrCreate` by slug, so re-running doesn't duplicate.

  **Must NOT do**:
  - Do NOT create skills with >3000 tokens of instructions
  - Do NOT execute any existing conversation modifications

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`developing-with-ai-sdk`]
    - `developing-with-ai-sdk`: Need to write quality AI instructions for each skill

  **Parallelization**:
  - **Can Run In Parallel**: YES (needs Task 1 models to exist)
  - **Parallel Group**: Wave 1 (after Task 1)
  - **Blocks**: Tasks 8, 12
  - **Blocked By**: Tasks 1, 2

  **References**:

  **Pattern References**:
  - `database/seeders/DatabaseSeeder.php` — Where to register new seeders
  - `app/Agent/SystemPromptBuilder.php:10-25` — The existing Aegis persona text to base default agent on

  **WHY Each Reference Matters**:
  - The SystemPromptBuilder identity section contains the canonical Aegis persona. The default agent's persona should match.

  **Acceptance Criteria**:
  - [ ] `php artisan db:seed --class=SkillSeeder` creates 7 skills
  - [ ] `php artisan db:seed --class=DefaultAgentSeeder` creates 1 agent with all skills attached
  - [ ] Re-running seeders doesn't create duplicates
  - [ ] `Skill::where('source', 'built_in')->count()` === 7
  - [ ] `Agent::where('slug', 'aegis')->first()->skills()->count()` === 7

  **QA Scenarios**:

  ```
  Scenario: Seed built-in skills and verify content quality
    Tool: Bash (tinker)
    Steps:
      1. Run: php artisan db:seed --class=SkillSeeder
      2. Query: Skill::where('source', 'built_in')->pluck('name', 'category')
      3. Assert 7 skills exist across categories
      4. Assert each skill's instructions length > 200 characters (meaningful content)
      5. Assert each skill's instructions length < 15000 characters (within limit)
    Expected Result: 7 quality built-in skills seeded
    Evidence: .sisyphus/evidence/task-4-skills-seeded.txt

  Scenario: Default agent has all skills
    Tool: Bash (tinker)
    Steps:
      1. Run seeders
      2. Query: Agent::where('slug', 'aegis')->first()->skills->pluck('name')
      3. Assert all 7 skill names present
    Expected Result: Default Aegis agent equipped with all built-in skills
    Evidence: .sisyphus/evidence/task-4-default-agent.txt
  ```

  **Commit**: YES (groups with Wave 1)

- [ ] 5. Wave 1 test verification

  **What to do**:
  - Run `php artisan test --compact` (FULL test suite)
  - Verify ALL 314+ existing tests still pass
  - Verify ALL new model tests pass
  - Run `vendor/bin/pint --dirty --format agent` to ensure formatting
  - If any existing test fails, FIX the issue before proceeding — likely a migration side-effect on conversations table

  **Must NOT do**:
  - Do NOT skip failing tests
  - Do NOT modify existing test assertions unless the schema change genuinely requires it

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: NO — must run after Tasks 1-4 complete
  - **Blocks**: Wave 2 (Tasks 6-9)
  - **Blocked By**: Tasks 1, 2, 3, 4

  **Acceptance Criteria**:
  - [ ] `php artisan test --compact` → ALL PASS (0 failures)
  - [ ] `vendor/bin/pint --dirty --format agent` → pass
  - [ ] New model test count: at least 15 assertions across Agent, Skill, Project, Task tests

  **QA Scenarios**:

  ```
  Scenario: Full test suite passes after Wave 1
    Tool: Bash
    Steps:
      1. Run: php artisan test --compact 2>&1
      2. Assert output contains "0 failures" or all dots (no ⨯)
      3. Count total tests — should be 314+ existing + new model tests
    Expected Result: Zero regressions from schema changes
    Evidence: .sisyphus/evidence/task-5-wave1-tests.txt
  ```

  **Commit**: YES (final Wave 1 commit)
  - Message: `feat(agents): add agent, skill, project, task models with migrations and seeders`
  - Pre-commit: `php artisan test --compact && vendor/bin/pint --dirty --format agent`

### Wave 2: Core Agent Infrastructure

- [ ] 6. DynamicAgent class

  **What to do**:
  - Create `app/Agent/DynamicAgent.php` implementing `Agent`, `Conversational`, `HasMiddleware`, `HasTools`
  - Use traits: `Promptable`, `RemembersConversations`
  - Constructor accepts `Agent` Eloquent model: `public function __construct(private \App\Models\Agent $agentModel) {}`
  - `instructions()`: Build from `$this->agentModel->persona` + injected skills (delegate to SystemPromptBuilder with agent context)
  - `provider()`: Return `$this->agentModel->provider` if set, otherwise `config('aegis.agent.default_provider')`. Support failover chain same as AegisAgent.
  - `model()`: Return `$this->agentModel->model` if set, otherwise resolve via ProviderManager.
  - `tools()`: Filter `ToolRegistry::all()` by `$this->agentModel->allowedToolNames()`. If agent has no tool restrictions (empty pivot), return all tools (same as AegisAgent).
  - `middleware()`: Return `[InjectMemoryContext::class, TrackTokenUsage::class]` (same as AegisAgent initially).
  - Add `#[MaxSteps(50)]`, `#[Timeout(120)]` attributes (same as AegisAgent).
  - Add `forConversation($conversationId)` method similar to AegisAgent — binds to conversation, sets up ConversationStore.
  - Key difference from AegisAgent: persona comes from DB, tools are filtered, skills are injected.

  **Must NOT do**:
  - Do NOT modify AegisAgent.php
  - Do NOT modify ToolRegistry.php
  - Do NOT modify AppServiceProvider.php bindings for AegisAgent

  **Recommended Agent Profile**:
  - **Category**: `deep`
    - Reason: Complex class that must correctly implement 4 interfaces, configure from DB, and integrate with SDK
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]
    - `developing-with-ai-sdk`: Must correctly implement Agent, Conversational, HasMiddleware, HasTools
    - `pest-testing`: Must test with Agent::fake()

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2 (with Tasks 7, 8)
  - **Blocks**: Tasks 7, 8, 13, 20, 21, 24
  - **Blocked By**: Tasks 1, 3, 5

  **References**:

  **Pattern References**:
  - `app/Agent/AegisAgent.php` — THE primary reference. DynamicAgent follows exact same interface pattern but configures from Eloquent model instead of hardcoded values. Study: forConversation(), tools(), middleware(), instructions(), provider(), model()
  - `app/Agent/ProviderManager.php` — Provider/model resolution logic. DynamicAgent should use this same service.
  - `app/Agent/AegisConversationStore.php` — Conversation persistence. DynamicAgent reuses this.

  **API/Type References**:
  - `vendor/laravel/ai/src/Contracts/Agent.php` — Interface contract
  - `vendor/laravel/ai/src/Contracts/Conversational.php` — Conversation interface
  - `vendor/laravel/ai/src/Contracts/HasTools.php` — Tools interface
  - `vendor/laravel/ai/src/Contracts/HasMiddleware.php` — Middleware interface

  **WHY Each Reference Matters**:
  - `AegisAgent.php` is the exact pattern to follow — DynamicAgent is essentially "AegisAgent but configurable from DB." Read every method and replicate with DB-backed alternatives.
  - `ProviderManager::resolve()` handles provider/model resolution with failover — reuse, don't reinvent.

  **Acceptance Criteria**:
  - [ ] `DynamicAgent` implements Agent, Conversational, HasMiddleware, HasTools
  - [ ] Can instantiate: `new DynamicAgent(Agent::factory()->create())`
  - [ ] `instructions()` includes agent persona text
  - [ ] `tools()` returns filtered set when agent has tool restrictions
  - [ ] `tools()` returns all tools when agent has no restrictions
  - [ ] `php artisan test --compact --filter=DynamicAgentTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: DynamicAgent with tool restrictions
    Tool: Bash (pest)
    Steps:
      1. Create Agent with 2 allowed tools (web_search, knowledge_search)
      2. Instantiate DynamicAgent with that model
      3. Call tools() and assert only 2 tools returned
      4. Create Agent with NO tool restrictions
      5. Assert tools() returns all tools from ToolRegistry
    Expected Result: Per-agent tool filtering works
    Evidence: .sisyphus/evidence/task-6-tool-filtering.txt

  Scenario: DynamicAgent falls back to defaults
    Tool: Bash (pest)
    Steps:
      1. Create Agent with provider=null, model=null
      2. Instantiate DynamicAgent
      3. Assert provider() returns config default
      4. Assert model() returns resolved default
    Expected Result: Null DB values fall back to aegis config
    Evidence: .sisyphus/evidence/task-6-fallback.txt
  ```

  **Commit**: YES (groups with Wave 2)

- [ ] 7. AgentRegistry service

  **What to do**:
  - Create `app/Agent/AgentRegistry.php` — service class for resolving and instantiating agents.
  - Methods:
    - `resolve(int $id): DynamicAgent` — Find agent by ID, instantiate DynamicAgent
    - `resolveBySlug(string $slug): DynamicAgent` — Find by slug
    - `resolveDefault(): AegisAgent|DynamicAgent` — Return default Aegis agent (existing AegisAgent for backward compat, or DynamicAgent wrapping the 'aegis' DB record)
    - `all(): Collection` — Return all active Agent models
    - `forConversation(Conversation $conversation): Agent` — If conversation has agent_id, return DynamicAgent for that agent. If null, return default AegisAgent.
  - Register as singleton in `AppServiceProvider`: `$this->app->singleton(AgentRegistry::class)`
  - The key method is `forConversation()` — this is what Chat.php will use to resolve which agent handles a conversation.

  **Must NOT do**:
  - Do NOT remove existing AegisAgent singleton binding
  - Do NOT modify Chat.php yet (that's Wave 3)

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2 (with Tasks 6, 8)
  - **Blocks**: Tasks 11, 13, 24, 28
  - **Blocked By**: Tasks 1, 3, 6

  **References**:

  **Pattern References**:
  - `app/Tools/ToolRegistry.php` — Similar registry pattern: resolve by name, return instances
  - `app/Providers/AppServiceProvider.php:29` — Where AegisAgent is registered as singleton

  **WHY Each Reference Matters**:
  - ToolRegistry shows the existing registry pattern in this codebase. AgentRegistry follows the same style.
  - AppServiceProvider shows where to register the new singleton without breaking existing bindings.

  **Acceptance Criteria**:
  - [ ] `app(AgentRegistry::class)->resolve($agentId)` returns DynamicAgent
  - [ ] `app(AgentRegistry::class)->resolveDefault()` returns working agent
  - [ ] `app(AgentRegistry::class)->forConversation($conv)` returns correct agent based on agent_id
  - [ ] `php artisan test --compact --filter=AgentRegistryTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Resolve agent for conversation with and without agent_id
    Tool: Bash (pest)
    Steps:
      1. Create conversation with agent_id=null → forConversation() returns AegisAgent (or default DynamicAgent)
      2. Create agent, create conversation with that agent_id → forConversation() returns DynamicAgent
      3. Assert DynamicAgent's instructions() includes the agent's persona
    Expected Result: Correct agent resolved based on conversation's agent_id
    Evidence: .sisyphus/evidence/task-7-agent-resolution.txt
  ```

  **Commit**: YES (groups with Wave 2)

- [ ] 8. SystemPromptBuilder: renderSkillsSection()

  **What to do**:
  - Add new method `renderSkillsSection(?Agent $agentModel = null): string` to SystemPromptBuilder.
  - If `$agentModel` is null, return empty string (backward compatible — AegisAgent doesn't pass agent model).
  - If `$agentModel` has skills, render: `"## Specialized Knowledge\n\n"` followed by each skill: `"### {skill.name}\n{skill.instructions}\n\n"`.
  - Add optional `?Agent $agentModel = null` parameter to `build()` method. Insert skills section after the identity section and before tools section.
  - This keeps `build()` backward-compatible: calling `build()` without args works exactly as before. Calling `build($conversation, $agentModel)` adds skills.
  - Add validation in render: skip skills with empty instructions, log warning for skills exceeding 3000 tokens.

  **Must NOT do**:
  - Do NOT restructure existing sections in SystemPromptBuilder
  - Do NOT change the signature of existing public methods (only ADD optional params)
  - Do NOT remove or reorder any existing sections

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2 (with Tasks 6, 7)
  - **Blocks**: Tasks 12, 25
  - **Blocked By**: Tasks 1, 4, 5

  **References**:

  **Pattern References**:
  - `app/Agent/SystemPromptBuilder.php:63-74` — `renderPreferencesSection()` — exact pattern to follow for renderSkillsSection()
  - `app/Agent/SystemPromptBuilder.php:20-30` — `build()` method showing section ordering

  **Test References**:
  - `tests/Feature/SystemPromptBuilderTest.php` — Existing tests that MUST NOT break

  **WHY Each Reference Matters**:
  - `renderPreferencesSection()` is the exact template: query models, format as text, return string. New skills section follows same pattern.
  - Existing SystemPromptBuilderTest assertions define the contract — adding skills must not change existing output when no agent model is passed.

  **Acceptance Criteria**:
  - [ ] `build()` without agent model → output identical to current (backward compatible)
  - [ ] `build($conversation, $agentModel)` with agent having 2 skills → output contains both skill names and instructions
  - [ ] Skills section appears after identity, before tools
  - [ ] `php artisan test --compact --filter=SystemPromptBuilder` → ALL existing tests PASS
  - [ ] New tests for skills injection PASS

  **QA Scenarios**:

  ```
  Scenario: Backward compatibility — no agent model
    Tool: Bash (pest)
    Steps:
      1. Build prompt with SystemPromptBuilder->build() (no args, like today)
      2. Assert output contains "You are Aegis"
      3. Assert output does NOT contain "Specialized Knowledge"
    Expected Result: Existing behavior unchanged
    Evidence: .sisyphus/evidence/task-8-backward-compat.txt

  Scenario: Skills injection with agent model
    Tool: Bash (pest)
    Steps:
      1. Create agent with 2 skills (Fitness, Research)
      2. Build prompt with build($conversation, $agent)
      3. Assert output contains "## Specialized Knowledge"
      4. Assert output contains "### Fitness" and "### Research"
      5. Assert output contains actual skill instructions text
    Expected Result: Skills injected into system prompt
    Evidence: .sisyphus/evidence/task-8-skills-injection.txt
  ```

  **Commit**: YES (groups with Wave 2)

- [ ] 9. Wave 2 test verification

  **What to do**:
  - Run `php artisan test --compact` (FULL test suite)
  - Verify ALL existing tests still pass (especially SystemPromptBuilderTest, AegisAgentTest)
  - Verify new DynamicAgent, AgentRegistry, and skills injection tests pass
  - Run `vendor/bin/pint --dirty --format agent`

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: NO
  - **Blocks**: Wave 3
  - **Blocked By**: Tasks 6, 7, 8

  **Acceptance Criteria**:
  - [ ] `php artisan test --compact` → ALL PASS
  - [ ] `vendor/bin/pint --dirty --format agent` → pass

  **Commit**: YES
  - Message: `feat(agents): add DynamicAgent class, AgentRegistry, and skill injection in SystemPromptBuilder`

### Wave 3: Agent & Skill UI

- [ ] 10. ConversationSidebar redesign

  **What to do**:
  - Modify `app/Livewire/ConversationSidebar.php` to group the sidebar into three sections:
    1. **Agents** — List active user-created agents (from Agent model, excluding the default 'aegis' agent). Each shows avatar + name. Clicking opens/creates a conversation thread for that agent. Show "+ New Agent" link that navigates to agent creation.
    2. **Conversations** — Existing conversation list, but filter to show only conversations with `agent_id = null` (general conversations not tied to a specific agent). Keep existing behavior.
    3. **Projects** — List active projects (from Project model). Each shows title + progress (completed tasks / total tasks). Clicking navigates to project dashboard page.
  - Update `resources/views/livewire/conversation-sidebar.blade.php` with collapsible sections, section headers, and the three groups.
  - Add Alpine.js for section collapse/expand with `x-show` and `x-transition`.
  - Agent thread creation: when user clicks an agent in sidebar, find or create a conversation with that `agent_id`. Open it in the chat view.
  - Style with Tailwind: section headers with subtle separators, agent avatars as emoji badges, project progress as small bar.

  **Must NOT do**:
  - Do NOT remove existing conversation functionality
  - Do NOT create the agent management pages (that's Task 11)
  - Do NOT create the project dashboard (that's Task 15)

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
    - Reason: Significant UI redesign with Livewire + Alpine.js + Tailwind
  - **Skills**: [`livewire-development`, `tailwindcss-development`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 3 (with Tasks 11, 12, 13)
  - **Blocks**: Task 13
  - **Blocked By**: Tasks 3, 9

  **References**:

  **Pattern References**:
  - `app/Livewire/ConversationSidebar.php` — THE file being modified. Read completely first.
  - `resources/views/livewire/conversation-sidebar.blade.php` — Current template
  - `app/Livewire/Settings.php` — Example of Livewire component with Alpine.js interactions

  **WHY Each Reference Matters**:
  - Must read existing sidebar implementation completely before modifying — preserve all existing functionality while adding new sections.

  **Acceptance Criteria**:
  - [ ] Sidebar shows three sections: Agents, Conversations, Projects
  - [ ] Clicking an agent creates/opens a conversation thread for that agent
  - [ ] Conversations section only shows agent_id=null conversations
  - [ ] Projects section shows active projects with task counts
  - [ ] Existing conversation click behavior unchanged
  - [ ] `php artisan test --compact --filter=ConversationSidebar` → PASS (if existing tests)

  **QA Scenarios**:

  ```
  Scenario: Sidebar shows agents section with created agents
    Tool: Playwright (playwright skill)
    Preconditions: Seed default agent + create 2 custom agents via factory
    Steps:
      1. Navigate to main page
      2. Assert sidebar contains section header "Agents" or similar
      3. Assert 2 custom agents visible (not the default 'aegis' agent)
      4. Assert each shows avatar emoji + name
      5. Click on first agent → new conversation created
    Expected Result: Agents section populated, clickable
    Evidence: .sisyphus/evidence/task-10-sidebar-agents.png

  Scenario: Projects section shows active projects
    Tool: Playwright
    Preconditions: Create 2 active projects with tasks
    Steps:
      1. Navigate to main page
      2. Assert sidebar contains "Projects" section
      3. Assert 2 projects visible with titles
      4. Assert progress indicator shows (e.g., "2/5 tasks")
    Expected Result: Projects visible with progress
    Evidence: .sisyphus/evidence/task-10-sidebar-projects.png
  ```

  **Commit**: YES (groups with Wave 3)

- [ ] 11. Agent management pages

  **What to do**:
  - Create `app/Livewire/AgentSettings.php` — Livewire component for agent CRUD.
  - **Agent List View**: Shows all user-created agents in a grid/list. Each card shows: avatar, name, skill count, tool count, active/inactive badge. Actions: Edit, Delete, Toggle Active.
  - **Agent Create/Edit Form**: Name (text input), Avatar (emoji picker or text input), Persona (textarea — the personality instructions), Skills (multi-select checkboxes from available skills), Tools (multi-select checkboxes from ToolRegistry::names()), Provider/Model (dropdowns, optional — falls back to default), Active toggle.
  - **Agent Delete**: Soft behavior — mark is_active=false, or hard delete with confirmation. Conversations for deleted agents show "[Agent deleted]" in sidebar.
  - Create `resources/views/livewire/agent-settings.blade.php` with Tailwind styling.
  - Add route/navigation: accessible from Settings page or sidebar "+ New Agent" link.
  - Validation: Name required, max 50 chars. Persona required. Max 10 agents enforced.
  - Wire methods: `createAgent()`, `updateAgent($id)`, `deleteAgent($id)`, `toggleActive($id)`.

  **Must NOT do**:
  - Do NOT build conversational agent creation here (that's Task 24)
  - Do NOT modify Settings.php — AgentSettings is a separate component

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
  - **Skills**: [`livewire-development`, `tailwindcss-development`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 3 (with Tasks 10, 12, 13)
  - **Blocks**: None directly
  - **Blocked By**: Tasks 1, 7, 9

  **References**:

  **Pattern References**:
  - `app/Livewire/Settings.php` — Existing settings component pattern. Follow same Livewire conventions.
  - `resources/views/livewire/settings.blade.php` — UI pattern: card layout, toggles, form inputs

  **Test References**:
  - `tests/Feature/SettingsTest.php` (if exists) — Pattern for testing Livewire settings components

  **WHY Each Reference Matters**:
  - Settings.php shows the established pattern for Livewire CRUD in this app — form structure, wire:model, wire:click, card layouts. AgentSettings should look consistent.

  **Acceptance Criteria**:
  - [ ] Agent list view shows all agents with correct details
  - [ ] Create form creates agent with name, persona, skills, tools
  - [ ] Edit form updates agent fields
  - [ ] Delete removes agent (with confirmation)
  - [ ] Max 10 agents enforced (create button disabled at limit)
  - [ ] `php artisan test --compact --filter=AgentSettingsTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Full CRUD lifecycle via UI
    Tool: Playwright
    Steps:
      1. Navigate to agent settings page
      2. Click "Create Agent"
      3. Fill: Name="TestCoach", Persona="You are a test coach", select 2 skills
      4. Save → assert agent appears in list
      5. Click Edit → change name to "TestCoach2" → Save
      6. Assert name updated in list
      7. Click Delete → confirm → assert agent removed
    Expected Result: Full CRUD works through UI
    Evidence: .sisyphus/evidence/task-11-agent-crud.png

  Scenario: Max 10 agents limit
    Tool: Bash (tinker) + Playwright
    Steps:
      1. Create 10 agents via factory (including default)
      2. Navigate to agent settings
      3. Assert "Create Agent" button is disabled or shows limit message
    Expected Result: Cannot create more than 10 agents
    Evidence: .sisyphus/evidence/task-11-agent-limit.png
  ```

  **Commit**: YES (groups with Wave 3)

- [ ] 12. Skill management in Settings

  **What to do**:
  - Create `app/Livewire/SkillSettings.php` — Livewire component for skill management.
  - **Skill Library View**: Shows all skills grouped by source: Built-in, Marketplace (future), Custom. Each shows: name, category badge, description, "Used by N agents" count.
  - **Skill Detail/Edit**: Click a skill to see full instructions. For user_created skills: editable name, description, instructions, category. For built_in: read-only.
  - **Create Custom Skill**: Form with name, description, instructions (textarea with token counter), category dropdown.
  - **Token Counter**: Live character count on instructions textarea. Show warning when approaching 15000 chars (~3000 tokens). Use Alpine.js `x-text` to show count.
  - Create `resources/views/livewire/skill-settings.blade.php`.
  - Add navigation from Settings page.
  - Validation: instructions max 15000 chars, name required, max 100 chars.

  **Must NOT do**:
  - Do NOT integrate with marketplace yet
  - Do NOT allow editing built-in skills (read-only)
  - Do NOT create skill assignment UI here (that's in Agent edit form, Task 11)

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
  - **Skills**: [`livewire-development`, `tailwindcss-development`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 3 (with Tasks 10, 11, 13)
  - **Blocked By**: Tasks 4, 8, 9

  **References**:

  **Pattern References**:
  - `app/Livewire/Settings.php` — Component pattern
  - `resources/views/livewire/settings.blade.php` — Card layout, form patterns

  **Acceptance Criteria**:
  - [ ] Skill library shows built-in and custom skills grouped by source
  - [ ] Custom skill creation works with validation
  - [ ] Token counter shows live character count on instructions
  - [ ] Built-in skills are read-only
  - [ ] `php artisan test --compact --filter=SkillSettingsTest` → PASS

  **Commit**: YES (groups with Wave 3)

- [ ] 13. Chat component: agent-aware

  **What to do**:
  - Modify `app/Livewire/Chat.php` to be agent-aware:
    - When opening a conversation with `agent_id`, resolve the agent via `AgentRegistry::forConversation()` instead of always using AegisAgent.
    - Show agent avatar and name in the chat header when in an agent conversation.
    - Use DynamicAgent for agent conversations, AegisAgent for regular conversations.
    - Ensure streaming works with DynamicAgent (same `->stream()` interface).
    - Ensure `ExtractMemoriesJob` still dispatches after agent responses.
    - Pass agent model to SystemPromptBuilder in DynamicAgent so skills are injected.
  - Update `resources/views/livewire/chat.blade.php`:
    - Show agent name/avatar in header when agent_id is set
    - Optionally show agent name next to assistant messages in agent conversations
  - Handle edge case: conversation's agent was deleted → fall back to default AegisAgent.

  **Must NOT do**:
  - Do NOT change AegisAgent behavior
  - Do NOT add multi-agent in single conversation
  - Do NOT add @mention parsing yet (that's Task 29)

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
  - **Skills**: [`livewire-development`, `tailwindcss-development`, `developing-with-ai-sdk`]

  **Parallelization**:
  - **Can Run In Parallel**: YES (needs Task 10 for sidebar to route to agent conversations)
  - **Parallel Group**: Wave 3
  - **Blocks**: Tasks 21, 29
  - **Blocked By**: Tasks 6, 7, 10, 9

  **References**:

  **Pattern References**:
  - `app/Livewire/Chat.php` — THE file being modified. Read completely.
  - `app/Livewire/Chat.php:80-140` — `generateResponse()` and `generateStreamedResponse()` — where agent is invoked
  - `app/Agent/AgentRegistry.php` — Task 7's output, used to resolve agent per conversation

  **WHY Each Reference Matters**:
  - Chat.php is the critical path for all conversations. Must understand every method before modifying. The agent resolution change happens in generateResponse() where AegisAgent is currently instantiated.

  **Acceptance Criteria**:
  - [ ] Regular conversations (agent_id=null) use AegisAgent — unchanged
  - [ ] Agent conversations use DynamicAgent resolved via AgentRegistry
  - [ ] Chat header shows agent name/avatar for agent conversations
  - [ ] Streaming works with DynamicAgent
  - [ ] ExtractMemoriesJob still dispatches after agent responses
  - [ ] Deleted agent → falls back to default

  **QA Scenarios**:

  ```
  Scenario: Chat with user-created agent uses DynamicAgent
    Tool: Playwright
    Preconditions: Create agent "FitCoach" with Fitness skill
    Steps:
      1. Click FitCoach in sidebar → opens agent conversation
      2. Assert chat header shows "FitCoach" name and avatar
      3. Send message "What's a good workout?"
      4. Assert response received (streaming works)
      5. Assert response tone matches FitCoach persona (if detectable)
    Expected Result: Agent conversation uses correct persona
    Evidence: .sisyphus/evidence/task-13-agent-chat.png

  Scenario: Regular conversation still uses AegisAgent
    Tool: Playwright
    Steps:
      1. Click "+ New Chat" (regular conversation)
      2. Assert chat header shows "Aegis" or default header
      3. Send message → response received
    Expected Result: Existing behavior unchanged
    Evidence: .sisyphus/evidence/task-13-regular-chat.png
  ```

  **Commit**: YES (groups with Wave 3)

- [ ] 14. Wave 3 test verification

  **What to do**:
  - Run `php artisan test --compact` (FULL test suite)
  - Verify sidebar, agent settings, skill settings, and chat tests pass
  - Run `vendor/bin/pint --dirty --format agent`

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Blocked By**: Tasks 10-13

  **Acceptance Criteria**:
  - [ ] `php artisan test --compact` → ALL PASS
  - [ ] `vendor/bin/pint --dirty --format agent` → pass

  **Commit**: YES
  - Message: `feat(agents): redesign sidebar with agent/project sections, add agent and skill management UI`

### Wave 4: Project Dashboard & Agent Tools

- [ ] 15. Project dashboard page

  **What to do**:
  - Create `app/Livewire/ProjectDashboard.php` — full-page Livewire component shown when a project is clicked in sidebar.
  - **Dashboard Layout**: Replace chat area with project view. Show: project title (editable inline), description, status badge, category badge, deadline (with date picker), progress bar (completed/total tasks).
  - **Task List**: List all tasks grouped by status (In Progress, Pending, Completed). Each task card shows: title, description (truncated), assigned type badge (agent/user/system), priority indicator, deadline, output preview (if completed). Actions: Mark complete, Edit, Delete.
  - **Task Creation**: Inline "Add Task" form at top: title, description, assign to (dropdown of agents + "me"), priority, deadline.
  - **Project Knowledge Panel**: Collapsible section showing project knowledge entries (key-value pairs from `ProjectKnowledge`). Read-only display — knowledge is added by agents during task execution.
  - **Navigation**: Back button to return to chat. Breadcrumb: Projects > {Project Name}.
  - Create `resources/views/livewire/project-dashboard.blade.php` with Tailwind grid layout.
  - Add route: `/projects/{project}` → ProjectDashboard component.
  - Wire methods: `updateProject()`, `createTask()`, `completeTask($id)`, `deleteTask($id)`, `updateTaskStatus($id, $status)`.

  **Must NOT do**:
  - Do NOT create project/task tools for the AI (that's Tasks 16-17)
  - Do NOT implement task execution logic (that's Wave 5)
  - Do NOT add drag-and-drop — keep it simple with status buttons

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
    - Reason: Full-page dashboard with complex layout, task cards, progress visualization
  - **Skills**: [`livewire-development`, `tailwindcss-development`, `pest-testing`]
    - `livewire-development`: Complex Livewire component with multiple wire: methods and real-time updates
    - `tailwindcss-development`: Grid layout, card design, progress bars, responsive
    - `pest-testing`: Test component rendering, task CRUD via Livewire

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 4 (with Tasks 16, 17, 18)
  - **Blocks**: None directly
  - **Blocked By**: Tasks 2, 14

  **References**:

  **Pattern References**:
  - `app/Livewire/Chat.php` — Full-page Livewire component pattern. ProjectDashboard replaces the chat area similarly.
  - `resources/views/livewire/chat.blade.php` — Layout pattern for main content area
  - `app/Livewire/Settings.php` — Card layout, form inputs, wire:model patterns

  **API/Type References**:
  - `app/Models/Project.php` (Task 2) — Project model with relationships
  - `app/Models/Task.php` (Task 2) — Task model with status, assigned_type, priority

  **WHY Each Reference Matters**:
  - Chat.php shows how the main content area works in this app — ProjectDashboard needs to fit the same layout container.
  - Task model's status/assigned_type fields define the grouping and display logic.

  **Acceptance Criteria**:
  - [ ] `/projects/{id}` route renders ProjectDashboard
  - [ ] Project title, description, status, progress bar display correctly
  - [ ] Tasks grouped by status (In Progress, Pending, Completed)
  - [ ] Inline task creation works
  - [ ] Task status change works (buttons to complete, etc.)
  - [ ] Project knowledge panel shows entries
  - [ ] `php artisan test --compact --filter=ProjectDashboardTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: View project dashboard with tasks
    Tool: Playwright (playwright skill)
    Preconditions: Create project "Tax Prep 2026" with 3 tasks (1 completed, 2 pending) via factory
    Steps:
      1. Navigate to /projects/{id}
      2. Assert page shows "Tax Prep 2026" as title
      3. Assert progress bar shows "1/3" or "33%"
      4. Assert "Completed" section has 1 task
      5. Assert "Pending" section has 2 tasks
    Expected Result: Dashboard renders with correct task grouping and progress
    Evidence: .sisyphus/evidence/task-15-dashboard-view.png

  Scenario: Create task from dashboard
    Tool: Playwright
    Steps:
      1. Navigate to project dashboard
      2. Fill task form: title="Gather W-2s", priority="high"
      3. Click Add Task
      4. Assert new task appears in "Pending" section
      5. Assert task count incremented in progress bar
    Expected Result: Task created and visible immediately
    Evidence: .sisyphus/evidence/task-15-create-task.png
  ```

  **Commit**: YES (groups with Wave 4)

- [ ] 16. ProjectTool for AegisAgent

  **What to do**:
  - Create `app/Tools/ProjectTool.php` implementing `Laravel\Ai\Contracts\Tool`.
  - Tool name: `manage_projects`
  - Actions: `create`, `list`, `update`, `archive`, `get`
  - **create**: title (required), description, category, deadline → creates Project, returns project summary.
  - **list**: Optional status filter (active, paused, completed, archived). Returns list of projects with task counts.
  - **update**: project_id (required), title, description, status, category, deadline → updates project.
  - **archive**: project_id → sets status to archived.
  - **get**: project_id → returns full project detail with tasks and knowledge.
  - Follow exact same pattern as `ProactiveTaskTool`: schema() with JsonSchema, handle() with match on action.
  - AI should use this when user says things like "I need to prepare my taxes" (create a project), "show me my projects" (list), "tax project is done" (archive).

  **Must NOT do**:
  - Do NOT auto-create tasks (that's TaskTool's job)
  - Do NOT add project knowledge writing (that's the ProjectKnowledgeService)
  - Do NOT modify ToolRegistry (auto-discovered)

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
    - Reason: Tool with multiple actions, JSON schema, validation, similar to existing ProactiveTaskTool
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]
    - `developing-with-ai-sdk`: Must implement Tool interface correctly with schema/handle
    - `pest-testing`: Feature tests with mocked agent calls

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 4 (with Tasks 15, 17, 18)
  - **Blocks**: Tasks 18, 20
  - **Blocked By**: Tasks 2, 14

  **References**:

  **Pattern References**:
  - `app/Tools/ProactiveTaskTool.php` — **THE primary reference**. ProjectTool follows the identical pattern: name(), description(), schema(), handle() with action match, private methods per action. Copy this structure exactly.
  - `app/Tools/ProactiveTaskTool.php:36-49` — schema() method showing JsonSchema usage with string, integer, enum, description, required

  **API/Type References**:
  - `vendor/laravel/ai/src/Contracts/Tool.php` — Tool interface contract
  - `vendor/laravel/ai/src/Tools/Request.php` — Request object methods (string, integer, boolean)
  - `app/Models/Project.php` (Task 2) — Model with scopes and relationships

  **WHY Each Reference Matters**:
  - ProactiveTaskTool is the gold standard for tool implementation in this codebase. Its exact structure (action-based dispatch, validation in each method, informative string returns) must be replicated.

  **Acceptance Criteria**:
  - [ ] `manage_projects` tool registered in ToolRegistry (auto-discovered)
  - [ ] `create` action creates a project and returns summary
  - [ ] `list` action returns projects with task counts
  - [ ] `update` action modifies project fields
  - [ ] `archive` action sets status to archived
  - [ ] `get` action returns full detail with tasks
  - [ ] `php artisan test --compact --filter=ProjectToolTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Create and list projects via tool
    Tool: Bash (pest)
    Steps:
      1. Call ProjectTool->handle() with action=create, title="Tax Prep", category="finance"
      2. Assert response contains "created" and project title
      3. Call handle() with action=list
      4. Assert response contains "Tax Prep"
    Expected Result: CRUD operations work through tool interface
    Evidence: .sisyphus/evidence/task-16-project-tool-crud.txt

  Scenario: Archive project
    Tool: Bash (pest)
    Steps:
      1. Create project, note ID
      2. Call handle() with action=archive, project_id=ID
      3. Assert Project::find(ID)->status === 'archived'
    Expected Result: Archive changes status correctly
    Evidence: .sisyphus/evidence/task-16-project-archive.txt
  ```

  **Commit**: YES (groups with Wave 4)

- [ ] 17. TaskTool for AegisAgent

  **What to do**:
  - Create `app/Tools/TaskTool.php` implementing `Laravel\Ai\Contracts\Tool`.
  - Tool name: `manage_tasks`
  - Actions: `create`, `list`, `update`, `complete`, `assign`
  - **create**: title (required), project_id (optional — standalone tasks OK), description, assigned_type (agent/user, default user), assigned_id (agent slug when type=agent), priority (low/medium/high), deadline.
  - **list**: Optional filters: project_id, status, assigned_type. Returns task list with status and assignment info.
  - **update**: task_id (required), any updatable fields.
  - **complete**: task_id (required), output (optional — deliverable text). Sets status=completed, completed_at=now().
  - **assign**: task_id, assigned_type, assigned_id → reassign task. If assigning to agent with type "agent", dispatch ExecuteAgentTaskJob (Wave 5).
  - Follow ProactiveTaskTool pattern exactly.
  - When AI creates a task assigned to an agent, return confirmation: "Created task '{title}' assigned to {agent_name}."

  **Must NOT do**:
  - Do NOT implement ExecuteAgentTaskJob yet (that's Wave 5) — just create the task record with assigned_type/id
  - Do NOT allow assigning tasks to system agents (only user agents and "user")
  - Do NOT create subtask management (keep flat for now)

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 4 (with Tasks 15, 16, 18)
  - **Blocks**: Tasks 18, 20, 28
  - **Blocked By**: Tasks 2, 14

  **References**:

  **Pattern References**:
  - `app/Tools/ProactiveTaskTool.php` — Same tool pattern. Identical structure.
  - `app/Tools/ProjectTool.php` (Task 16) — Sibling tool, same conventions

  **API/Type References**:
  - `app/Models/Task.php` (Task 2) — Task model with assigned_type, assigned_id, status
  - `app/Models/Agent.php` (Task 1) — For resolving agent slug to ID

  **WHY Each Reference Matters**:
  - ProactiveTaskTool defines the tool implementation standard. TaskTool is structurally identical but operates on Task model.

  **Acceptance Criteria**:
  - [ ] `manage_tasks` tool registered in ToolRegistry
  - [ ] `create` with project_id creates task linked to project
  - [ ] `create` without project_id creates standalone task
  - [ ] `complete` sets status=completed and completed_at
  - [ ] `assign` updates assigned_type and assigned_id
  - [ ] `list` filters by project_id, status, assigned_type
  - [ ] `php artisan test --compact --filter=TaskToolTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Create task linked to project
    Tool: Bash (pest)
    Steps:
      1. Create project via factory
      2. Call TaskTool->handle() with action=create, title="Gather W-2", project_id=project.id
      3. Assert response contains task title
      4. Assert Task::where('project_id', project.id)->count() === 1
    Expected Result: Task created with project association
    Evidence: .sisyphus/evidence/task-17-task-create.txt

  Scenario: Complete task with output
    Tool: Bash (pest)
    Steps:
      1. Create task
      2. Call handle() with action=complete, task_id=ID, output="W-2 downloaded and filed"
      3. Assert Task::find(ID)->status === 'completed'
      4. Assert Task::find(ID)->output === "W-2 downloaded and filed"
      5. Assert Task::find(ID)->completed_at is not null
    Expected Result: Task completion records output and timestamp
    Evidence: .sisyphus/evidence/task-17-task-complete.txt
  ```

  **Commit**: YES (groups with Wave 4)

- [ ] 18. AegisAgent system prompt updates

  **What to do**:
  - Add new sections to `SystemPromptBuilder::build()`:
    - `renderAgentsSection()`: List all active user-created agents with name, description, skills. Purpose: Aegis knows about available agents and can suggest delegation.
    - `renderProjectsSection(?Conversation $conversation)`: Show active projects with status and pending task count. Purpose: Aegis has context about ongoing projects and can reference them.
  - These are awareness sections — Aegis doesn't use ProjectTool/TaskTool by reading these sections, but they give context for natural conversation ("You mentioned your tax project — it has 3 pending tasks").
  - Insert after skills section, before memory instructions section.
  - Each section should be concise — max 500 tokens for agents, max 500 tokens for projects.
  - Add instructions telling Aegis: "When the user describes a multi-step goal, consider using manage_projects to create a project. When tasks need specialized help, consider assigning to an appropriate agent."

  **Must NOT do**:
  - Do NOT change existing section order
  - Do NOT remove any existing sections
  - Do NOT make agents/projects sections mandatory — they return empty string when no data exists

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
    - Reason: Careful modification of critical SystemPromptBuilder with backward compatibility
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: NO — depends on ProjectTool and TaskTool being defined
  - **Parallel Group**: Wave 4 (after Tasks 16, 17)
  - **Blocks**: Task 24
  - **Blocked By**: Tasks 16, 17

  **References**:

  **Pattern References**:
  - `app/Agent/SystemPromptBuilder.php:63-74` — renderPreferencesSection() pattern for new sections
  - `app/Agent/SystemPromptBuilder.php:23-35` — build() method section ordering

  **Test References**:
  - `tests/Feature/SystemPromptBuilderTest.php` — Existing tests that MUST continue passing

  **WHY Each Reference Matters**:
  - Must follow exact same section pattern and ensure build() without agent model produces identical output.

  **Acceptance Criteria**:
  - [ ] `build()` without agents/projects → same as before (empty sections)
  - [ ] `build()` with active agents → output contains agent names and skill summaries
  - [ ] `build()` with active projects → output contains project titles and task counts
  - [ ] Prompt includes tool usage guidance for manage_projects and manage_tasks
  - [ ] `php artisan test --compact --filter=SystemPromptBuilder` → ALL existing tests PASS

  **QA Scenarios**:

  ```
  Scenario: System prompt includes agent awareness
    Tool: Bash (pest)
    Steps:
      1. Create 2 agents (FitCoach, TaxAdvisor) via factory
      2. Build system prompt
      3. Assert output contains "FitCoach" and "TaxAdvisor"
      4. Assert output contains guidance about delegation
    Expected Result: Aegis knows about available agents
    Evidence: .sisyphus/evidence/task-18-agent-awareness.txt

  Scenario: System prompt includes project awareness
    Tool: Bash (pest)
    Steps:
      1. Create project "Tax Prep" with 3 tasks (1 complete, 2 pending)
      2. Build system prompt
      3. Assert output contains "Tax Prep"
      4. Assert output mentions pending task count
    Expected Result: Aegis has context about ongoing projects
    Evidence: .sisyphus/evidence/task-18-project-awareness.txt
  ```

  **Commit**: YES (groups with Wave 4)

- [ ] 19. Wave 4 test verification

  **What to do**:
  - Run `php artisan test --compact` (FULL test suite)
  - Verify ALL existing tests still pass (especially SystemPromptBuilderTest)
  - Verify new ProjectDashboard, ProjectTool, TaskTool tests pass
  - Run `vendor/bin/pint --dirty --format agent`

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Blocked By**: Tasks 15-18

  **Acceptance Criteria**:
  - [ ] `php artisan test --compact` → ALL PASS
  - [ ] `vendor/bin/pint --dirty --format agent` → pass

  **Commit**: YES
  - Message: `feat(projects): add project dashboard, ProjectTool, TaskTool, and agent awareness in system prompt`

### Wave 5: Task Execution & Knowledge Flow

- [ ] 20. ExecuteAgentTaskJob (background task execution)

  **What to do**:
  - Create `app/Jobs/ExecuteAgentTaskJob.php` implementing `ShouldQueue`.
  - Constructor: `__construct(private readonly int $taskId)`
  - `handle()`:
    1. Load Task with project relationship.
    2. Set task status to `in_progress`.
    3. Resolve the assigned agent via `AgentRegistry::resolve($task->assigned_id)`.
    4. Create a temporary conversation (or find agent's existing conversation) — use `Conversation::create(['agent_id' => $task->assigned_id])`.
    5. Build prompt from task: "You have been assigned a task:\n\nTitle: {title}\nDescription: {description}\n\nProject context: {project.title} - {project.description}\n\nComplete this task and provide your output."
    6. Send prompt to DynamicAgent via `$agent->forConversation($conversation)->send($prompt)`.
    7. Capture response text → set `task->output` and `task->status = 'completed'`, `task->completed_at = now()`.
    8. If task has project_id, store output in ProjectKnowledge: `ProjectKnowledge::create(['project_id' => ..., 'task_id' => ..., 'key' => $task->title, 'value' => $response, 'type' => 'artifact'])`.
    9. Dispatch Livewire event or broadcast so dashboard updates in real-time.
  - Error handling: On failure, set task status to `pending` (retry), log error. Max 2 retries.
  - Properties: `$tries = 2`, `$timeout = 120`, `$backoff = 30`.

  **Must NOT do**:
  - Do NOT dispatch sub-jobs for delegation (that's Wave 7)
  - Do NOT send notifications yet (that's Wave 8)
  - Do NOT use AegisAgent — always use DynamicAgent via AgentRegistry

  **Recommended Agent Profile**:
  - **Category**: `deep`
    - Reason: Complex async job integrating DynamicAgent, ConversationStore, ProjectKnowledge, error handling
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]
    - `developing-with-ai-sdk`: Must correctly invoke DynamicAgent in queued context
    - `pest-testing`: Test with Queue::fake(), Agent::fake()

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 5 (with Tasks 21, 22)
  - **Blocks**: Tasks 28, 32
  - **Blocked By**: Tasks 6, 16, 17, 19

  **References**:

  **Pattern References**:
  - `app/Jobs/ExtractMemoriesJob.php` — Job pattern in this codebase: ShouldQueue, Queueable trait, constructor with primitive types, handle() with DI, error handling with try/catch and Log::debug
  - `app/Agent/DynamicAgent.php` (Task 6) — How to instantiate and send prompts to a dynamic agent

  **API/Type References**:
  - `app/Agent/AgentRegistry.php` (Task 7) — `resolve($id)` to get DynamicAgent for assigned agent
  - `app/Models/Task.php` (Task 2) — Task status lifecycle
  - `app/Models/ProjectKnowledge.php` (Task 2) — Where to store task output

  **WHY Each Reference Matters**:
  - ExtractMemoriesJob is the exact job pattern: constructor takes IDs (not models for serialization), handle() resolves services via DI, wraps in try/catch. Follow this exactly.

  **Acceptance Criteria**:
  - [ ] `ExecuteAgentTaskJob::dispatch($taskId)` dispatches to queue
  - [ ] Job updates task status: pending → in_progress → completed
  - [ ] Completed task has output from agent response
  - [ ] Task output stored as ProjectKnowledge when project exists
  - [ ] Failed execution resets status to pending
  - [ ] `php artisan test --compact --filter=ExecuteAgentTaskJobTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Background task execution lifecycle
    Tool: Bash (pest)
    Steps:
      1. Create agent, project, and task (assigned_type=agent, assigned_id=agent.id)
      2. Fake the AI response with Agent::fake()
      3. Dispatch ExecuteAgentTaskJob synchronously
      4. Assert task->fresh()->status === 'completed'
      5. Assert task->fresh()->output is not empty
      6. Assert task->fresh()->completed_at is not null
      7. Assert ProjectKnowledge::where('task_id', task.id)->exists()
    Expected Result: Full execution lifecycle completes
    Evidence: .sisyphus/evidence/task-20-job-lifecycle.txt

  Scenario: Job failure handling
    Tool: Bash (pest)
    Steps:
      1. Create task, fake agent to throw exception
      2. Run job, assert exception handled
      3. Assert task status is NOT 'completed' (stays pending or in_progress)
    Expected Result: Graceful failure without crashing queue
    Evidence: .sisyphus/evidence/task-20-job-failure.txt
  ```

  **Commit**: YES (groups with Wave 5)

- [ ] 21. Collaborative task execution

  **What to do**:
  - Implement "collaborative" task execution mode — when a task is assigned to an agent, instead of background execution, the task prompt appears in the agent's conversation thread for interactive collaboration.
  - In `TaskTool::assign()`: When `assigned_type = 'agent'` and task priority is NOT "high" (high = background), insert a message into the agent's conversation thread:
    - System message: "📋 New Task: {title}\n{description}\n\nReply to work on this task. When done, I'll mark it complete."
  - In `Chat.php`: Detect when an agent conversation has pending tasks. Show a small "Tasks" indicator in chat header with count.
  - Add wire method `completeTaskFromChat($taskId, $output)` — allows marking a task complete from within the conversation, extracting the last N messages as output.
  - Modify the conversation view to show task context when a task is being worked on (optional: collapsible task card at top of chat).

  **Must NOT do**:
  - Do NOT force all agent tasks into conversation — only collaborative type
  - Do NOT modify ExecuteAgentTaskJob (that handles background tasks)
  - Do NOT implement full task workflow (just the message insertion and completion)

  **Recommended Agent Profile**:
  - **Category**: `deep`
    - Reason: Integrates task system with conversation flow, requires careful Chat.php modification
  - **Skills**: [`livewire-development`, `developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 5 (with Tasks 20, 22)
  - **Blocks**: None directly
  - **Blocked By**: Tasks 6, 13, 19

  **References**:

  **Pattern References**:
  - `app/Livewire/Chat.php` (Task 13 output) — Agent-aware chat, where task context gets added
  - `app/Models/Message.php` — Message model for inserting system messages
  - `app/Services/ConversationService.php` — How messages are created in conversations

  **WHY Each Reference Matters**:
  - Chat.php is the target for modification. Must understand the agent-aware flow from Task 13 before adding task awareness on top.

  **Acceptance Criteria**:
  - [ ] Assigning a task to agent inserts message in agent's conversation
  - [ ] Chat header shows pending task count for agent conversations
  - [ ] `completeTaskFromChat()` marks task complete with output
  - [ ] `php artisan test --compact --filter=CollaborativeTaskTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Task appears as message in agent conversation
    Tool: Playwright
    Preconditions: Create agent "FitCoach", create task assigned to FitCoach
    Steps:
      1. Navigate to FitCoach conversation in sidebar
      2. Assert conversation contains system message with task title
      3. Assert chat header shows "1 task" indicator
    Expected Result: Task is visible in agent's conversation thread
    Evidence: .sisyphus/evidence/task-21-collab-task-message.png

  Scenario: Complete task from conversation
    Tool: Playwright
    Steps:
      1. In agent conversation with pending task
      2. Have a back-and-forth with agent about the task
      3. Click "Complete Task" button (or equivalent)
      4. Assert task marked complete
      5. Assert task output captured
    Expected Result: Task completion from within conversation works
    Evidence: .sisyphus/evidence/task-21-collab-complete.png
  ```

  **Commit**: YES (groups with Wave 5)

- [ ] 22. Project knowledge service + InjectProjectContext middleware

  **What to do**:
  - Create `app/Services/ProjectKnowledgeService.php`:
    - `store(int $projectId, string $key, string $value, string $type = 'note', ?int $taskId = null): ProjectKnowledge`
    - `getForProject(int $projectId): Collection` — all knowledge for a project
    - `search(int $projectId, string $query): Collection` — keyword search in knowledge values
    - `summarize(int $projectId): string` — Generate a concise summary of all project knowledge (for injection into prompts)
  - Create `app/Agent/Middleware/InjectProjectContext.php`:
    - Implements agent middleware interface (same as InjectMemoryContext).
    - Before agent response: check if the conversation's agent has any assigned tasks with projects. If yes, inject project context into the system prompt: project title, recent knowledge entries, pending tasks.
    - Limit: max 5 knowledge entries, max 500 tokens of project context.
    - Register in DynamicAgent::middleware() after InjectMemoryContext.
  - The flow: Task execution → output saved as ProjectKnowledge → next conversation in same project → InjectProjectContext adds that knowledge to agent's context.

  **Must NOT do**:
  - Do NOT inject project context into AegisAgent (only DynamicAgent via middleware)
  - Do NOT modify InjectMemoryContext
  - Do NOT add vector/embedding for project knowledge (simple text search is sufficient)

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 5 (with Tasks 20, 21)
  - **Blocks**: Task 32
  - **Blocked By**: Tasks 2, 6, 19

  **References**:

  **Pattern References**:
  - `app/Agent/Middleware/InjectMemoryContext.php` — **THE pattern for InjectProjectContext**. Same middleware contract, same injection approach, but with project knowledge instead of memories.
  - `app/Memory/MemoryService.php` — Service pattern: CRUD methods, query builder, return models

  **API/Type References**:
  - `app/Models/ProjectKnowledge.php` (Task 2) — Model for storing/querying knowledge

  **WHY Each Reference Matters**:
  - InjectMemoryContext shows exactly how to write agent middleware that injects context. InjectProjectContext follows the identical pattern with different data source.

  **Acceptance Criteria**:
  - [ ] `ProjectKnowledgeService::store()` creates knowledge entry
  - [ ] `ProjectKnowledgeService::summarize()` returns concise string
  - [ ] InjectProjectContext middleware injects project knowledge into DynamicAgent prompts
  - [ ] Context limited to 500 tokens
  - [ ] `php artisan test --compact --filter=ProjectKnowledgeService` → PASS
  - [ ] `php artisan test --compact --filter=InjectProjectContext` → PASS

  **QA Scenarios**:

  ```
  Scenario: Project knowledge flows from task to agent context
    Tool: Bash (pest)
    Steps:
      1. Create project, add 3 knowledge entries via service
      2. Create agent with middleware, assign conversation to project context
      3. Build agent system prompt via DynamicAgent
      4. Assert prompt contains project knowledge entries
    Expected Result: Knowledge from completed tasks appears in agent context
    Evidence: .sisyphus/evidence/task-22-knowledge-flow.txt

  Scenario: Knowledge summarization
    Tool: Bash (tinker)
    Steps:
      1. Create project with 5 knowledge entries
      2. Call ProjectKnowledgeService::summarize(projectId)
      3. Assert summary is non-empty and < 2000 characters
    Expected Result: Concise summary generated
    Evidence: .sisyphus/evidence/task-22-summarize.txt
  ```

  **Commit**: YES (groups with Wave 5)

- [ ] 23. Wave 5 test verification

  **What to do**:
  - Run `php artisan test --compact` (FULL test suite)
  - Verify task execution, collaborative tasks, and project knowledge tests pass
  - Run `vendor/bin/pint --dirty --format agent`

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Blocked By**: Tasks 20-22

  **Acceptance Criteria**:
  - [ ] `php artisan test --compact` → ALL PASS
  - [ ] `vendor/bin/pint --dirty --format agent` → pass

  **Commit**: YES
  - Message: `feat(tasks): add background and collaborative task execution with project knowledge flow`

### Wave 6: Conversational Intelligence

- [ ] 24. AgentCreatorTool (create agents from natural language)

  **What to do**:
  - Create `app/Tools/AgentCreatorTool.php` implementing `Laravel\Ai\Contracts\Tool`.
  - Tool name: `create_agent`
  - Purpose: Aegis uses this tool when user says things like "I want a fitness coach" or "Create me an agent for tax advice."
  - Schema: `name` (string, required), `persona` (string — personality instructions), `suggested_skills` (array of skill slugs), `suggested_tools` (array of tool names), `avatar` (string — emoji).
  - `handle()`:
    1. Validate max 10 agents not exceeded.
    2. Generate slug from name.
    3. Create Agent record with persona, avatar.
    4. Attach suggested skills (by slug lookup) and tools.
    5. Create initial conversation for the agent.
    6. Return confirmation: "Created agent '{name}' with skills: {list}. You can chat with them in the sidebar, or visit Settings > Agents to customize."
  - **Key insight**: The AI doesn't call this tool directly with raw user text. Aegis interprets "I want a fitness coach" → internally decides to call create_agent with structured params. The persona text is AI-generated based on context.
  - If the user wants to refine: "Make the fitness coach more strict" → Aegis calls `manage_agents` (below) to update persona.
  - Add a lightweight `manage_agents` action to this tool: `update` (name, persona, skills, tools changes), `delete`, `list`.

  **Must NOT do**:
  - Do NOT create a separate "agent configuration wizard" flow
  - Do NOT auto-assign ALL tools — only suggest relevant ones
  - Do NOT bypass the 10-agent limit

  **Recommended Agent Profile**:
  - **Category**: `deep`
    - Reason: Complex tool that generates structured agent config from natural language intent
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 6 (with Tasks 25, 26)
  - **Blocks**: None directly
  - **Blocked By**: Tasks 6, 7, 18, 23

  **References**:

  **Pattern References**:
  - `app/Tools/ProactiveTaskTool.php` — Tool action pattern (create/list/update/delete)
  - `app/Livewire/AgentSettings.php` (Task 11) — Same creation logic, but UI-based. Reuse validation logic.
  - `app/Agent/AgentRegistry.php` (Task 7) — Used to check agent count limits

  **API/Type References**:
  - `app/Models/Agent.php` (Task 1) — Agent model creation
  - `app/Models/Skill.php` (Task 1) — Skill slug lookup for suggested_skills

  **WHY Each Reference Matters**:
  - ProactiveTaskTool defines the action-based tool pattern. AgentCreatorTool follows the same dispatch structure.
  - AgentSettings has the same validation rules (max 10, required fields) — reuse or call shared validation.

  **Acceptance Criteria**:
  - [ ] `create_agent` tool registered in ToolRegistry
  - [ ] `create` action creates agent with persona, skills, tools, avatar
  - [ ] New agent's conversation created automatically
  - [ ] Max 10 agents enforced (returns error at limit)
  - [ ] `update` action modifies agent fields
  - [ ] `list` action returns active agents with skill summaries
  - [ ] `php artisan test --compact --filter=AgentCreatorToolTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Create agent via tool (simulating conversational creation)
    Tool: Bash (pest)
    Steps:
      1. Call AgentCreatorTool->handle() with name="FitCoach", persona="Strict fitness coach", suggested_skills=["health-fitness"], avatar="💪"
      2. Assert response contains "Created agent"
      3. Assert Agent::where('slug', 'fitcoach')->exists()
      4. Assert agent has health-fitness skill attached
      5. Assert Conversation::where('agent_id', agent.id)->exists()
    Expected Result: Agent created with full configuration
    Evidence: .sisyphus/evidence/task-24-agent-creator.txt

  Scenario: Enforce agent limit
    Tool: Bash (pest)
    Steps:
      1. Create 10 agents via factory
      2. Call create_agent tool
      3. Assert response contains error about limit
      4. Assert Agent::count() === 10 (not 11)
    Expected Result: Limit enforced gracefully
    Evidence: .sisyphus/evidence/task-24-agent-limit.txt
  ```

  **Commit**: YES (groups with Wave 6)

- [ ] 25. Context window budget calculator

  **What to do**:
  - Create `app/Services/ContextBudgetCalculator.php`:
    - `calculate(Agent $agentModel): array` — Returns token budget breakdown: `['base_prompt' => N, 'skills' => N, 'memories' => N, 'project_context' => N, 'total' => N, 'model_limit' => N, 'remaining_for_conversation' => N]`.
    - Use approximate token counting: ~4 chars per token for English text.
    - Fetch: base system prompt size, all attached skill instruction lengths, typical memory injection size, project context size.
    - Compare total against model's context window (from ProviderManager/config).
    - Return warning if total system prompt > 30% of model context window.
  - Integrate into AgentSettings.php (Task 11): Show budget breakdown when editing an agent. Visual bar showing prompt budget usage.
  - Integrate into SystemPromptBuilder: Log warning when built prompt exceeds budget threshold.
  - Purpose: Prevents users from attaching so many skills that the agent's context window overflows, leaving no room for conversation.

  **Must NOT do**:
  - Do NOT block agent creation on budget — just warn
  - Do NOT implement tiktoken or exact tokenization — approximate is fine
  - Do NOT query actual provider API for token limits — use config values

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 6 (with Tasks 24, 26)
  - **Blocks**: None
  - **Blocked By**: Tasks 8, 23

  **References**:

  **Pattern References**:
  - `app/Agent/SystemPromptBuilder.php` — Source of system prompt content to measure
  - `config/aegis.php` — Model context window configurations

  **API/Type References**:
  - `app/Agent/ProviderManager.php` — Model capabilities/limits

  **WHY Each Reference Matters**:
  - SystemPromptBuilder::build() generates the full prompt. Calculator needs to estimate its size without actually building it (or build it and measure).

  **Acceptance Criteria**:
  - [ ] `ContextBudgetCalculator::calculate()` returns token breakdown
  - [ ] Warning emitted when prompt > 30% of model context
  - [ ] Budget visualization shown in agent edit UI
  - [ ] `php artisan test --compact --filter=ContextBudgetCalculatorTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Budget calculation for agent with many skills
    Tool: Bash (pest)
    Steps:
      1. Create agent with 5 skills (max), each with 2000 chars of instructions
      2. Calculate budget
      3. Assert total > 0
      4. Assert skills component reflects 5 * ~2000 chars / 4 ≈ 2500 tokens
      5. Assert remaining_for_conversation > 0
    Expected Result: Budget accurately reflects skill content
    Evidence: .sisyphus/evidence/task-25-budget-calc.txt

  Scenario: Budget warning for oversized prompt
    Tool: Bash (pest)
    Steps:
      1. Create agent with skills totaling ~12000 chars
      2. Calculate budget
      3. Assert warning present when total > 30% of model limit
    Expected Result: Warning triggered for large prompts
    Evidence: .sisyphus/evidence/task-25-budget-warning.txt
  ```

  **Commit**: YES (groups with Wave 6)

- [ ] 26. Skill suggestion when creating agents

  **What to do**:
  - Enhance AgentCreatorTool (Task 24) and AgentSettings (Task 11) with smart skill suggestions.
  - In `AgentCreatorTool`: When creating an agent, if `suggested_skills` is empty, auto-suggest based on persona text. Simple keyword matching: "fitness" → health-fitness skill, "tax"/"finance" → finance skill, "research" → research skill, "writing" → writing skill, etc.
  - Create `app/Services/SkillSuggestionService.php`:
    - `suggestForPersona(string $persona): Collection` — Returns suggested Skill models based on keyword matching against persona text.
    - `suggestForProject(Project $project): Collection` — Returns skills relevant to project category.
    - Uses simple keyword-to-skill mapping (not AI-based — keep it deterministic and fast).
  - In AgentSettings create/edit form: When persona text changes, show "Suggested skills:" below with checkbox to accept. Use Livewire `wire:model.live.debounce.500ms` on persona textarea to trigger suggestions.
  - Keyword mapping stored as config or in the Skill model's metadata field.

  **Must NOT do**:
  - Do NOT use LLM for suggestions (too slow for real-time UI) — use keyword matching
  - Do NOT auto-attach skills without user confirmation in UI
  - Do NOT modify skill model structure

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`livewire-development`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 6 (with Tasks 24, 25)
  - **Blocks**: None
  - **Blocked By**: Tasks 4, 7, 23

  **References**:

  **Pattern References**:
  - `app/Livewire/AgentSettings.php` (Task 11) — Where suggestions are displayed
  - `app/Tools/AgentCreatorTool.php` (Task 24) — Where suggestions auto-apply for tool-based creation

  **API/Type References**:
  - `app/Models/Skill.php` (Task 1) — Skill model with category field for matching

  **Acceptance Criteria**:
  - [ ] `SkillSuggestionService::suggestForPersona("fitness coach")` returns health-fitness skill
  - [ ] Agent settings form shows dynamic suggestions as persona is typed
  - [ ] AgentCreatorTool auto-suggests skills when none specified
  - [ ] `php artisan test --compact --filter=SkillSuggestionServiceTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Skill suggestions in agent creation UI
    Tool: Playwright
    Steps:
      1. Navigate to agent settings, click Create Agent
      2. Type "I want to help with tax preparation and financial planning" in persona field
      3. Wait 500ms debounce
      4. Assert "Suggested skills" section appears
      5. Assert "Finance" skill is suggested
    Expected Result: Real-time skill suggestions based on persona text
    Evidence: .sisyphus/evidence/task-26-skill-suggestions.png

  Scenario: Keyword matching accuracy
    Tool: Bash (pest)
    Steps:
      1. Test suggestForPersona("workout routine tracker") → health-fitness
      2. Test suggestForPersona("help me study for exams") → education
      3. Test suggestForPersona("random conversation buddy") → empty (no strong match)
    Expected Result: Correct skills suggested for clear keywords, empty for ambiguous
    Evidence: .sisyphus/evidence/task-26-keyword-matching.txt
  ```

  **Commit**: YES (groups with Wave 6)

- [ ] 27. Wave 6 test verification

  **What to do**:
  - Run `php artisan test --compact` (FULL test suite)
  - Verify agent creation tool, budget calculator, skill suggestions tests pass
  - Run `vendor/bin/pint --dirty --format agent`

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Blocked By**: Tasks 24-26

  **Acceptance Criteria**:
  - [ ] `php artisan test --compact` → ALL PASS
  - [ ] `vendor/bin/pint --dirty --format agent` → pass

  **Commit**: YES
  - Message: `feat(agents): add conversational agent creation, context budget calculator, skill suggestions`

### Wave 7: Delegation & Handoff

- [ ] 28. Task-based delegation (agent creates task for another agent)

  **What to do**:
  - Enable agent-to-agent delegation via the task system:
    - When DynamicAgent A is working on a task and encounters something outside its expertise, it can call `manage_tasks` with `assigned_type='agent'` and `assigned_id` pointing to another agent.
    - Example: TaxAdvisor working on "Organize tax documents" creates subtask "Research tax deduction rules" and assigns to ResearchAgent.
  - Modify `TaskTool::assign()` to dispatch `ExecuteAgentTaskJob` when a task is assigned to an agent AND the task was created by another agent (delegation chain).
  - Add delegation metadata to Task model: `delegated_from` (nullable int — the conversation_id or task_id that initiated delegation).
  - Add depth tracking: `delegation_depth` (int, default 0). Increment when a task is delegated. Max depth: 3 (configurable in config/aegis.php).
  - When delegated task completes, feed output back to the originating agent's conversation as a system message: "✅ {AgentName} completed task '{title}': {output_summary}".
  - Callback flow: ExecuteAgentTaskJob → completes → check if delegated_from → find parent → inject result message.

  **Must NOT do**:
  - Do NOT allow circular delegation (A → B → A)
  - Do NOT exceed depth limit of 3
  - Do NOT allow system agents to be delegated to
  - Do NOT block the originating agent while waiting — delegation is async

  **Recommended Agent Profile**:
  - **Category**: `deep`
    - Reason: Complex async delegation with depth tracking, circular prevention, callback injection
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 7 (with Tasks 29, 30)
  - **Blocks**: Task 30
  - **Blocked By**: Tasks 7, 17, 20, 27

  **References**:

  **Pattern References**:
  - `app/Jobs/ExecuteAgentTaskJob.php` (Task 20) — Job that runs agent tasks. Delegation extends this with callback logic.
  - `app/Tools/TaskTool.php` (Task 17) — `assign()` method where delegation dispatch happens

  **API/Type References**:
  - `app/Models/Task.php` (Task 2) — Add delegation_depth and delegated_from columns

  **WHY Each Reference Matters**:
  - ExecuteAgentTaskJob is the execution engine. Delegation adds a post-completion callback step to feed results back to the originator.

  **Acceptance Criteria**:
  - [ ] Agent A can create task assigned to Agent B via TaskTool
  - [ ] Task includes delegation_depth and delegated_from
  - [ ] ExecuteAgentTaskJob dispatched for delegated task
  - [ ] Completed delegation feeds output back to originating conversation
  - [ ] Depth limit of 3 enforced (error at depth 4)
  - [ ] `php artisan test --compact --filter=DelegationTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Agent-to-agent delegation lifecycle
    Tool: Bash (pest)
    Steps:
      1. Create agents A and B
      2. Create task assigned to A, start execution
      3. During A's execution (faked), A creates subtask for B via TaskTool
      4. Assert subtask has delegation_depth=1, delegated_from set
      5. Dispatch B's task job (faked response)
      6. Assert result message injected into A's conversation
    Expected Result: Full delegation lifecycle with callback
    Evidence: .sisyphus/evidence/task-28-delegation-lifecycle.txt

  Scenario: Depth limit enforcement
    Tool: Bash (pest)
    Steps:
      1. Create task with delegation_depth=3
      2. Attempt to delegate further → assert error returned
      3. Assert no new task created at depth 4
    Expected Result: Delegation stopped at max depth
    Evidence: .sisyphus/evidence/task-28-depth-limit.txt
  ```

  **Commit**: YES (groups with Wave 7)

- [ ] 29. @mention routing

  **What to do**:
  - Implement @mention parsing in Chat.php:
    - When user types "@FitCoach what's a good workout?" in any conversation, detect the @mention.
    - Parse agent slug from mention (strip @, lowercase, match against Agent slugs).
    - Route the message to the mentioned agent's conversation thread:
      1. Find or create conversation with that agent_id.
      2. Insert the user's message (without the @mention prefix) into that conversation.
      3. Generate response from that agent (DynamicAgent).
      4. In the original conversation, insert a system message: "→ Routed to {AgentName}. Check their conversation for the response."
      5. Navigate sidebar to highlight the agent's conversation.
  - Add `parseAtMentions(string $message): ?Agent` helper method in Chat.php.
  - Pattern: `/^@(\w+)\s+(.+)$/` — must be at start of message, followed by the actual prompt.
  - If @mention agent not found, reply normally: "I don't know an agent named '{name}'. Your agents: {list}."

  **Must NOT do**:
  - Do NOT support multiple @mentions in one message
  - Do NOT render the response in the current conversation (route to agent's thread)
  - Do NOT modify AegisAgent to understand @mentions

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
    - Reason: Parsing + routing + conversation creation — moderate complexity
  - **Skills**: [`livewire-development`, `developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 7 (with Tasks 28, 30)
  - **Blocks**: None
  - **Blocked By**: Tasks 7, 13, 27

  **References**:

  **Pattern References**:
  - `app/Livewire/Chat.php` (Task 13 output) — Where @mention detection happens in `sendMessage()` or `generateResponse()`
  - `app/Agent/AgentRegistry.php` (Task 7) — `resolveBySlug()` used to find mentioned agent

  **WHY Each Reference Matters**:
  - Chat.php's sendMessage flow is where parsing must intercept BEFORE the normal AegisAgent response. AgentRegistry resolves the slug to a DynamicAgent instance.

  **Acceptance Criteria**:
  - [ ] "@fitcoach what workout today?" routes to FitCoach's conversation
  - [ ] Response generated in agent's thread, not current conversation
  - [ ] System message in original conversation confirms routing
  - [ ] Invalid @mention returns helpful error with agent list
  - [ ] `php artisan test --compact --filter=AtMentionRoutingTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: @mention routes to agent conversation
    Tool: Playwright
    Preconditions: Create agent "FitCoach" via factory
    Steps:
      1. Open main Aegis conversation
      2. Type "@fitcoach suggest a workout plan"
      3. Assert system message appears: "→ Routed to FitCoach"
      4. Click FitCoach in sidebar
      5. Assert FitCoach conversation contains the message and a response
    Expected Result: Message routed to correct agent thread
    Evidence: .sisyphus/evidence/task-29-mention-routing.png

  Scenario: Invalid @mention shows error
    Tool: Playwright
    Steps:
      1. Type "@nonexistent hello"
      2. Assert response contains "I don't know an agent named 'nonexistent'"
      3. Assert response lists available agents
    Expected Result: Helpful error with available agents
    Evidence: .sisyphus/evidence/task-29-invalid-mention.png
  ```

  **Commit**: YES (groups with Wave 7)

- [ ] 30. Delegation depth limiting + circular prevention

  **What to do**:
  - Implement robust safeguards in ExecuteAgentTaskJob and TaskTool:
    - **Depth limiting**: Track `delegation_depth` on every task. When creating a new delegated task, inherit parent's depth + 1. If depth > config('aegis.delegation.max_depth', 3), refuse and return error.
    - **Circular prevention**: Before creating a delegated task, check the delegation chain (parent → grandparent → ...). If the target agent already appears in the chain, refuse: "Cannot delegate to {agent} — circular delegation detected."
    - Build chain walker: `getDelegationChain(Task $task): Collection` — walks up via delegated_from to build the full chain.
  - Add config value: `config/aegis.php` → `delegation.max_depth` (default 3), `delegation.circular_check` (default true).
  - Add logging: Log every delegation attempt with depth, source agent, target agent. Warn on depth > 2.
  - Unit test edge cases: A→B→C→A (circular), A→B→C→D (depth 3, should work), A→B→C→D→E (depth 4, refused).

  **Must NOT do**:
  - Do NOT modify the Task model schema (delegation_depth and delegated_from already added in Task 28)
  - Do NOT block legitimate deep chains — just enforce the configurable max

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: NO — depends on Task 28's delegation infrastructure
  - **Parallel Group**: Wave 7 (after Task 28)
  - **Blocks**: None
  - **Blocked By**: Task 28

  **References**:

  **Pattern References**:
  - `app/Jobs/ExecuteAgentTaskJob.php` (Task 20/28) — Where depth check happens before execution
  - `app/Tools/TaskTool.php` (Task 17) — Where circular check happens before creating delegated task

  **WHY Each Reference Matters**:
  - Both the job and the tool are enforcement points. Task creation validates depth/circularity, job execution double-checks before running.

  **Acceptance Criteria**:
  - [ ] Depth 3 delegation succeeds (A→B→C→D)
  - [ ] Depth 4 delegation refused with clear error
  - [ ] Circular delegation (A→B→A) detected and refused
  - [ ] `getDelegationChain()` correctly walks the chain
  - [ ] Config values respected (aegis.delegation.max_depth)
  - [ ] `php artisan test --compact --filter=DelegationDepthTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Circular delegation prevention
    Tool: Bash (pest)
    Steps:
      1. Create agents A, B, C
      2. Create task: A delegates to B (depth 0→1)
      3. Create task: B delegates to C (depth 1→2)
      4. Attempt: C delegates to A → assert refused with "circular delegation" error
    Expected Result: Circular delegation caught and blocked
    Evidence: .sisyphus/evidence/task-30-circular-prevention.txt

  Scenario: Max depth enforcement
    Tool: Bash (pest)
    Steps:
      1. Create chain A→B→C→D (depth 3)
      2. Attempt D→E (depth 4) → assert refused with "max depth exceeded" error
      3. Change config to max_depth=5
      4. Retry D→E → assert succeeds
    Expected Result: Configurable depth limit enforced
    Evidence: .sisyphus/evidence/task-30-max-depth.txt
  ```

  **Commit**: YES (groups with Wave 7)

- [ ] 31. Wave 7 test verification

  **What to do**:
  - Run `php artisan test --compact` (FULL test suite)
  - Verify delegation, @mention, depth limiting tests pass
  - Run `vendor/bin/pint --dirty --format agent`

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Blocked By**: Tasks 28-30

  **Acceptance Criteria**:
  - [ ] `php artisan test --compact` → ALL PASS
  - [ ] `vendor/bin/pint --dirty --format agent` → pass

  **Commit**: YES
  - Message: `feat(delegation): add task-based delegation, @mention routing, depth limiting`

### Wave 8: Proactive Chief of Staff

- [ ] 32. ProjectReviewAgent (scan projects, generate nudges)

  **What to do**:
  - Create `app/Agent/ProjectReviewAgent.php` — a system agent (not user-visible) that scans active projects and generates nudge messages.
  - Extends laravel/ai Agent interface (same as MemoryExtractorAgent pattern — stateless, structured output).
  - `instructions()`: "You are a project review agent. Given a list of active projects with their tasks, deadlines, and progress, generate concise nudge messages for projects that need attention. Focus on: stalled projects (no activity in 3+ days), upcoming deadlines (within 48 hours), tasks stuck in_progress too long, projects with no assigned tasks."
  - `prompt($projectSummaries): array` — Takes project summaries, returns structured nudge list: `[{ project_id, message, urgency: 'low'|'medium'|'high' }]`.
  - Use `HasStructuredOutput` for typed response.
  - This agent is called by the proactive check-in system (Task 33), not by users directly.

  **Must NOT do**:
  - Do NOT make this agent visible in sidebar or settings
  - Do NOT create conversations for this agent
  - Do NOT modify AegisAgent or DynamicAgent

  **Recommended Agent Profile**:
  - **Category**: `deep`
    - Reason: Agent with structured output, project analysis logic, urgency classification
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 8 (with Tasks 33, 34)
  - **Blocks**: Tasks 33, 34
  - **Blocked By**: Tasks 20, 22, 31

  **References**:

  **Pattern References**:
  - `app/Agent/MemoryExtractorAgent.php` — **THE pattern for system agents**: stateless, structured output, prompt() method, no conversations. ProjectReviewAgent follows this identical pattern.
  - `app/Agent/ProfileSummaryAgent.php` — Another system agent pattern: takes input, returns structured result

  **API/Type References**:
  - `vendor/laravel/ai/src/Contracts/Agent.php` — Agent interface
  - `app/Models/Project.php` — Query active projects with tasks

  **WHY Each Reference Matters**:
  - MemoryExtractorAgent is the exact blueprint: system agent with structured output, called programmatically, no user interaction. Copy this pattern.

  **Acceptance Criteria**:
  - [ ] ProjectReviewAgent returns structured nudge array
  - [ ] Identifies stalled projects (no activity 3+ days)
  - [ ] Identifies upcoming deadlines (within 48 hours)
  - [ ] Assigns urgency levels (low/medium/high)
  - [ ] `php artisan test --compact --filter=ProjectReviewAgentTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Detect stalled project
    Tool: Bash (pest)
    Steps:
      1. Create project with tasks, set last task update to 5 days ago
      2. Call ProjectReviewAgent with project summaries
      3. Assert nudge returned for stalled project with urgency >= medium
    Expected Result: Stalled project detected and flagged
    Evidence: .sisyphus/evidence/task-32-stalled-detection.txt

  Scenario: Detect upcoming deadline
    Tool: Bash (pest)
    Steps:
      1. Create project with deadline = now + 24 hours
      2. Call ProjectReviewAgent
      3. Assert nudge returned mentioning deadline
      4. Assert urgency = high
    Expected Result: Deadline urgency escalated correctly
    Evidence: .sisyphus/evidence/task-32-deadline-detection.txt
  ```

  **Commit**: YES (groups with Wave 8)

- [ ] 33. Scheduled check-in system

  **What to do**:
  - Extend the existing proactive task system to include project check-ins:
    - Create an artisan command `aegis:projects:review` that:
      1. Queries all active projects with their tasks and deadlines.
      2. Builds project summaries (title, task counts by status, deadline, last activity date).
      3. Calls `ProjectReviewAgent::prompt($summaries)` to get nudges.
      4. For each nudge: Insert as a system message in the main Aegis conversation (agent_id=null).
      5. If Telegram is enabled: Also send nudges via Telegram for high-urgency items.
  - Register in Laravel scheduler (`routes/console.php` or `bootstrap/app.php`): run daily at 9am (configurable).
  - Add config: `config/aegis.php` → `proactive.project_review.enabled` (bool), `proactive.project_review.schedule` (cron string, default '0 9 * * *'), `proactive.project_review.telegram` (bool, send high-urgency via telegram).
  - Integrate with existing `RunProactiveTasksCommand` pattern — this is a new proactive task type, not a replacement.

  **Must NOT do**:
  - Do NOT modify existing RunProactiveTasksCommand or ProactiveTaskRunner
  - Do NOT run check-ins more frequently than hourly (prevent spam)
  - Do NOT send nudges for completed/archived projects

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES (after Task 32)
  - **Parallel Group**: Wave 8 (with Tasks 32, 34)
  - **Blocks**: None
  - **Blocked By**: Task 32

  **References**:

  **Pattern References**:
  - `app/Console/Commands/RunProactiveTasksCommand.php` — Existing proactive command pattern. New command follows same structure.
  - `routes/console.php` — Where to register the schedule (if it exists) or `bootstrap/app.php`
  - `app/Agent/ProactiveTaskRunner.php` — Existing proactive infrastructure to integrate with

  **WHY Each Reference Matters**:
  - RunProactiveTasksCommand shows the established pattern for scheduled AI actions. The new command follows the same conventions.

  **Acceptance Criteria**:
  - [ ] `php artisan aegis:projects:review` command exists and runs
  - [ ] Calls ProjectReviewAgent and generates nudges
  - [ ] Nudges inserted as messages in main Aegis conversation
  - [ ] High-urgency nudges sent to Telegram (when enabled)
  - [ ] Scheduled in console for daily execution
  - [ ] Config toggles work (enabled/disabled, schedule)
  - [ ] `php artisan test --compact --filter=ProjectReviewCommandTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Run project review command
    Tool: Bash
    Steps:
      1. Create 2 active projects: one stalled, one on-track
      2. Run: php artisan aegis:projects:review
      3. Assert command outputs nudge count
      4. Assert Message inserted in main conversation for stalled project
      5. Assert no nudge for on-track project
    Expected Result: Only actionable nudges generated
    Evidence: .sisyphus/evidence/task-33-review-command.txt

  Scenario: Disabled check-in produces no output
    Tool: Bash (pest)
    Steps:
      1. Set config aegis.proactive.project_review.enabled = false
      2. Run command
      3. Assert zero nudges generated
    Expected Result: Config toggle respected
    Evidence: .sisyphus/evidence/task-33-disabled.txt
  ```

  **Commit**: YES (groups with Wave 8)

- [ ] 34. Deadline reminders + cross-project awareness

  **What to do**:
  - Enhance the project review system with deadline-specific reminders:
    - **Deadline reminders**: For tasks with deadlines, send reminder messages at 48h, 24h, and 2h before deadline. Track which reminders have been sent (avoid duplicates).
    - Add `reminder_sent_at` JSON column to tasks (stores `{ '48h': '2026-01-15T...', '24h': null, '2h': null }`).
    - Logic in aegis:projects:review command (or separate sub-command): Check all tasks with upcoming deadlines, send appropriate reminder.
  - **Cross-project awareness**: When reviewing projects, detect connections between them:
    - Example: "Your 'Tax Prep' project has a task 'Gather investment docs' — your 'Investment Review' project might have relevant information."
    - Simple keyword overlap detection between project knowledge entries and task descriptions across projects.
    - Add to ProjectReviewAgent instructions: "Also identify potential connections between projects based on overlapping keywords or themes."
  - Reminders appear in the main Aegis conversation as system messages with clear formatting: "⏰ Reminder: Task '{title}' in project '{project}' is due in 24 hours."

  **Must NOT do**:
  - Do NOT send duplicate reminders (check reminder_sent_at)
  - Do NOT implement complex NLP for cross-project detection — simple keyword overlap is sufficient
  - Do NOT modify completed tasks' reminders

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES (after Task 32)
  - **Parallel Group**: Wave 8 (with Tasks 32, 33)
  - **Blocks**: None
  - **Blocked By**: Tasks 32, 33

  **References**:

  **Pattern References**:
  - `app/Console/Commands/RunProactiveTasksCommand.php` — Scheduling pattern
  - `app/Agent/ProjectReviewAgent.php` (Task 32) — Agent that generates reminders and cross-project insights

  **API/Type References**:
  - `app/Models/Task.php` — Add reminder_sent_at JSON column (migration)

  **Acceptance Criteria**:
  - [ ] 48h, 24h, 2h deadline reminders sent at correct times
  - [ ] Duplicate reminders prevented via reminder_sent_at tracking
  - [ ] Cross-project keyword connections detected
  - [ ] Reminders formatted clearly in main conversation
  - [ ] `php artisan test --compact --filter=DeadlineReminderTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Deadline reminder sent at correct time
    Tool: Bash (pest)
    Steps:
      1. Create task with deadline = now + 23 hours
      2. Run project review
      3. Assert 24h reminder sent (message in conversation)
      4. Assert reminder_sent_at['24h'] set on task
      5. Run review again
      6. Assert NO duplicate 24h reminder
    Expected Result: One-time reminder at correct threshold
    Evidence: .sisyphus/evidence/task-34-deadline-reminder.txt

  Scenario: Cross-project connection detected
    Tool: Bash (pest)
    Steps:
      1. Create project A with knowledge entry containing "tax deductions"
      2. Create project B with task titled "Research tax deductions"
      3. Run project review
      4. Assert nudge mentions connection between projects
    Expected Result: Keyword overlap detected across projects
    Evidence: .sisyphus/evidence/task-34-cross-project.txt
  ```

  **Commit**: YES (groups with Wave 8)

- [ ] 35. Wave 8 test verification

  **What to do**:
  - Run `php artisan test --compact` (FULL test suite)
  - Verify proactive review, check-ins, deadline reminders tests pass
  - Run `vendor/bin/pint --dirty --format agent`

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Blocked By**: Tasks 32-34

  **Acceptance Criteria**:
  - [ ] `php artisan test --compact` → ALL PASS
  - [ ] `vendor/bin/pint --dirty --format agent` → pass

  **Commit**: YES
  - Message: `feat(proactive): add ProjectReviewAgent, scheduled check-ins, deadline reminders`

### Wave 9: Advanced Features & Polish

- [ ] 36. Project templates (pre-defined task sets)

  **What to do**:
  - Create a project template system that pre-populates projects with common task sets:
    - Create `app/Models/ProjectTemplate.php`: `name` (string), `slug` (string, unique), `description` (text), `category` (string), `tasks` (JSON — array of task definitions: `[{title, description, assigned_type, priority, order}]`), `is_built_in` (boolean).
    - Create migration and factory.
    - Seed built-in templates:
      - **Tax Preparation**: "Gather income docs", "Gather deduction receipts", "Review previous year return", "File with tax software", "Review before submit"
      - **Home Project**: "Define scope", "Research options", "Get quotes", "Execute work", "Final inspection"
      - **Learning Goal**: "Identify learning resources", "Create study schedule", "Complete module 1", "Practice exercises", "Assessment/Review"
      - **Health Goal**: "Set baseline metrics", "Create routine", "Week 1 tracking", "Mid-point review", "Goal assessment"
    - Add `fromTemplate(ProjectTemplate $template, array $overrides = []): Project` static method on Project model — creates project with pre-populated tasks.
    - In ProjectTool: Add action `create_from_template` — takes template_slug and optional title override.
    - In AgentSettings/ProjectDashboard: Show "Start from template" button when creating a project.

  **Must NOT do**:
  - Do NOT allow editing built-in templates (create copies for customization)
  - Do NOT create more than 6 built-in templates
  - Do NOT make templates required — users can always create blank projects

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`pest-testing`, `livewire-development`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 9 (with Tasks 37, 38)
  - **Blocks**: None
  - **Blocked By**: Tasks 2, 35

  **References**:

  **Pattern References**:
  - `app/Models/Skill.php` (Task 1) — Model with is_built_in/source pattern, seeder with firstOrCreate
  - `database/seeders/SkillSeeder.php` (Task 4) — Seeder pattern for built-in content

  **API/Type References**:
  - `app/Models/Project.php` (Task 2) — Where fromTemplate() is added
  - `app/Tools/ProjectTool.php` (Task 16) — Where create_from_template action is added

  **WHY Each Reference Matters**:
  - SkillSeeder shows idempotent seeding of built-in content. ProjectTemplateSeeder follows the same firstOrCreate pattern.

  **Acceptance Criteria**:
  - [ ] 4 built-in project templates seeded
  - [ ] `Project::fromTemplate($template)` creates project with tasks
  - [ ] ProjectTool `create_from_template` action works
  - [ ] Templates shown in project creation UI
  - [ ] `php artisan test --compact --filter=ProjectTemplateTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Create project from template
    Tool: Bash (pest)
    Steps:
      1. Seed templates
      2. Call Project::fromTemplate(ProjectTemplate::where('slug', 'tax-preparation')->first(), ['title' => 'Tax 2026'])
      3. Assert project created with title "Tax 2026"
      4. Assert project has 5 tasks (from template)
      5. Assert tasks have correct order and titles
    Expected Result: Project pre-populated from template
    Evidence: .sisyphus/evidence/task-36-template-create.txt

  Scenario: Template via ProjectTool
    Tool: Bash (pest)
    Steps:
      1. Call ProjectTool->handle() with action=create_from_template, template_slug="tax-preparation"
      2. Assert project created with template tasks
    Expected Result: AI can create projects from templates
    Evidence: .sisyphus/evidence/task-36-template-tool.txt
  ```

  **Commit**: YES (groups with Wave 9)

- [ ] 37. Messaging channel routing (default agent for platforms)

  **What to do**:
  - Allow users to assign a default agent to each messaging platform (Telegram, iMessage, Discord, etc.):
    - Add `default_agent_id` column to messaging-related config (either in settings JSON or a new `channel_agents` table: `channel` (string), `agent_id` (foreignId nullable)).
    - When a message comes in from Telegram, look up the default agent for that channel. If set, route to that agent instead of AegisAgent.
    - In Settings: Show "Default Agent" dropdown per messaging channel (only channels that are enabled).
  - Modify `MessageRouter` (or its callers) to resolve the appropriate agent:
    - Check `channel_agents` table for the incoming channel.
    - If agent found: Use AgentRegistry to resolve DynamicAgent.
    - If not: Use default AegisAgent (backward compatible).
  - This enables use cases like: "My Telegram always uses FitCoach" or "Discord messages go to StudyBuddy."

  **Must NOT do**:
  - Do NOT modify the MessageRouter class internals — hook into the resolution point
  - Do NOT force users to set channel agents — default to AegisAgent
  - Do NOT break existing messaging flow

  **Recommended Agent Profile**:
  - **Category**: `unspecified-high`
  - **Skills**: [`livewire-development`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 9 (with Tasks 36, 38)
  - **Blocks**: None
  - **Blocked By**: Tasks 7, 35

  **References**:

  **Pattern References**:
  - `app/Messaging/MessageRouter.php` — Where incoming messages are routed. Integration point for agent resolution.
  - `app/Providers/AppServiceProvider.php:38-60` — MessageRouter registration and adapter wiring

  **API/Type References**:
  - `app/Agent/AgentRegistry.php` (Task 7) — `resolve()` for channel agents

  **WHY Each Reference Matters**:
  - MessageRouter is the gateway for all external messages. Understanding its flow is critical for injecting agent resolution without breaking existing routing.

  **Acceptance Criteria**:
  - [ ] Default agent configurable per messaging channel in Settings
  - [ ] Telegram messages routed to assigned agent (when set)
  - [ ] Unset channels use AegisAgent (backward compatible)
  - [ ] `php artisan test --compact --filter=ChannelAgentRoutingTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Telegram routed to assigned agent
    Tool: Bash (pest)
    Steps:
      1. Create agent "FitCoach"
      2. Set channel_agents: telegram → FitCoach
      3. Simulate incoming Telegram message
      4. Assert message processed by FitCoach (DynamicAgent with FitCoach persona)
      5. Assert response reflects FitCoach persona
    Expected Result: Channel-specific agent routing works
    Evidence: .sisyphus/evidence/task-37-channel-routing.txt

  Scenario: Unset channel uses default
    Tool: Bash (pest)
    Steps:
      1. Ensure no channel_agents entry for telegram
      2. Simulate incoming Telegram message
      3. Assert message processed by AegisAgent
    Expected Result: Backward compatible — default agent used
    Evidence: .sisyphus/evidence/task-37-default-fallback.txt
  ```

  **Commit**: YES (groups with Wave 9)

- [ ] 38. AI-generated skills (from conversation context)

  **What to do**:
  - Enable Aegis to create new skills from conversation patterns:
    - When a user repeatedly asks about a topic (e.g., asks about gardening 5+ times), Aegis can suggest: "I notice you ask about gardening often. Want me to create a 'Gardening' skill so your agents have this knowledge?"
    - Create `app/Services/SkillGeneratorService.php`:
      - `detectPatterns(Collection $recentConversations): array` — Analyze recent conversations for recurring topics.
      - `generateSkillContent(string $topic, Collection $relevantMessages): string` — Use AI to synthesize a skill's instructions from conversation history.
      - `proposeSkill(string $topic): Skill` — Creates a draft skill (source='ai_generated') for user review.
    - Integrate with ExtractMemoriesJob or a new `DetectSkillPatternsJob` — runs periodically after conversations to check for topic clusters.
    - In the main Aegis conversation: When pattern detected, Aegis proactively suggests: "I can create a '{topic}' skill. This would help your agents when discussing {topic}."
    - User confirms → skill created with source='ai_generated', shown in Skill Settings for review/edit.
    - Use simple topic detection: group memories by keyword, count occurrences, threshold = 5.

  **Must NOT do**:
  - Do NOT auto-create skills without user confirmation
  - Do NOT create duplicate skills (check existing slugs)
  - Do NOT generate skills longer than 3000 tokens
  - Do NOT use expensive LLM calls for pattern detection — use memory keyword analysis

  **Recommended Agent Profile**:
  - **Category**: `deep`
    - Reason: AI-driven skill generation from conversation patterns, topic clustering, LLM content synthesis
  - **Skills**: [`developing-with-ai-sdk`, `pest-testing`]

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 9 (with Tasks 36, 37)
  - **Blocks**: None
  - **Blocked By**: Tasks 4, 24, 35

  **References**:

  **Pattern References**:
  - `app/Jobs/ExtractMemoriesJob.php` — Job that runs post-conversation to extract patterns. SkillPatternJob follows same lifecycle.
  - `app/Agent/MemoryExtractorAgent.php` — Agent that processes text and returns structured data. SkillGeneratorService uses similar LLM call.

  **API/Type References**:
  - `app/Models/Skill.php` (Task 1) — Skill model with source='ai_generated'
  - `app/Memory/MemoryService.php` — Memory queries for topic detection

  **WHY Each Reference Matters**:
  - ExtractMemoriesJob shows the post-conversation processing pipeline. Skill pattern detection plugs into the same lifecycle.

  **Acceptance Criteria**:
  - [ ] `SkillGeneratorService::detectPatterns()` identifies recurring topics
  - [ ] `generateSkillContent()` produces coherent skill instructions from conversations
  - [ ] Proposed skill created with source='ai_generated' and is_active=false (draft)
  - [ ] User confirmation required before activation
  - [ ] No duplicate skills created
  - [ ] Generated instructions < 15000 chars
  - [ ] `php artisan test --compact --filter=SkillGeneratorServiceTest` → PASS

  **QA Scenarios**:

  ```
  Scenario: Detect topic pattern and propose skill
    Tool: Bash (pest)
    Steps:
      1. Create 6 memory entries with keyword "gardening" in various forms
      2. Call detectPatterns() with recent conversations
      3. Assert "gardening" detected as recurring topic
      4. Call proposeSkill("gardening")
      5. Assert Skill created with source='ai_generated', is_active=false
    Expected Result: Pattern detected and skill proposed
    Evidence: .sisyphus/evidence/task-38-pattern-detection.txt

  Scenario: Skill content generation quality
    Tool: Bash (pest)
    Steps:
      1. Create conversation messages about gardening (planting, watering, soil types)
      2. Call generateSkillContent("gardening", $messages) with Agent::fake()
      3. Assert generated content > 200 chars
      4. Assert generated content < 15000 chars
    Expected Result: Meaningful skill content synthesized
    Evidence: .sisyphus/evidence/task-38-skill-generation.txt
  ```

  **Commit**: YES (groups with Wave 9)

- [ ] 39. Wave 9 test verification

  **What to do**:
  - Run `php artisan test --compact` (FULL test suite)
  - Verify project templates, channel routing, AI-generated skills tests pass
  - Run `vendor/bin/pint --dirty --format agent`
  - This is the FINAL implementation wave — ensure zero regressions

  **Recommended Agent Profile**:
  - **Category**: `quick`
  - **Skills**: [`pest-testing`]

  **Parallelization**:
  - **Blocked By**: Tasks 36-38

  **Acceptance Criteria**:
  - [ ] `php artisan test --compact` → ALL PASS (total: 314+ existing + ~80-100 new tests)
  - [ ] `vendor/bin/pint --dirty --format agent` → pass
  - [ ] Zero regressions in any wave

  **Commit**: YES
  - Message: `feat(advanced): add project templates, messaging routing, AI-generated skills`

---

## Final Verification Wave (MANDATORY — after ALL implementation tasks)

> 4 review agents run in PARALLEL. ALL must APPROVE. Rejection → fix → re-run.

- [ ] F1. **Plan Compliance Audit** — `oracle`
  Read the plan end-to-end. For each "Must Have": verify implementation exists (read file, run tinker, query DB). For each "Must NOT Have": search codebase for forbidden patterns — reject with file:line if found. Check evidence files exist in `.sisyphus/evidence/`. Compare deliverables against plan. Verify hard limits enforced (max 10 agents, max 5 skills, max 3000 tokens per skill).
  Output: `Must Have [N/N] | Must NOT Have [N/N] | Tasks [N/N] | VERDICT: APPROVE/REJECT`

- [ ] F2. **Code Quality Review** — `unspecified-high`
  Run `vendor/bin/pint --dirty --format agent` + `php artisan test --compact`. Review all changed files for: `as any`/`@ts-ignore`, empty catches, console.log in prod, commented-out code, unused imports. Check AI slop: excessive comments, over-abstraction, generic names. Verify all new models have factories. Verify all factories are used in tests. Check that no `env()` calls exist outside config files.
  Output: `Pint [PASS/FAIL] | Tests [N pass/N fail] | Files [N clean/N issues] | VERDICT`

- [ ] F3. **Real Manual QA** — `unspecified-high` (+ `playwright` + `livewire-development` skills)
  Start from clean state (`php artisan migrate:fresh --seed`). Execute EVERY QA scenario from EVERY task. Test: create agent via Settings UI, create agent conversationally, chat with agent in its thread, create project, view dashboard, create task, execute background task, check proactive notifications, test @mention routing. Save screenshots to `.sisyphus/evidence/final-qa/`.
  Output: `Scenarios [N/N pass] | Integration [N/N] | Edge Cases [N tested] | VERDICT`

- [ ] F4. **Scope Fidelity Check** — `deep`
  For each task: read "What to do", read actual diff (`git log/diff`). Verify 1:1 — everything in spec was built, nothing beyond spec was built. Check "Must NOT do" compliance. Verify hard limits are enforced in code (not just docs). Flag unaccounted changes.
  Output: `Tasks [N/N compliant] | Unaccounted [CLEAN/N files] | VERDICT`

---

## Commit Strategy

| After Wave | Message | Verification |
|------------|---------|--------------|
| Wave 1 | `feat(agents): add agent, skill, project, task models with migrations and seeders` | `php artisan test --compact` |
| Wave 2 | `feat(agents): add DynamicAgent class, AgentRegistry, and skill injection in SystemPromptBuilder` | `php artisan test --compact` |
| Wave 3 | `feat(agents): redesign sidebar with agent/project sections, add agent and skill management UI` | `php artisan test --compact` |
| Wave 4 | `feat(projects): add project dashboard, ProjectTool, TaskTool, and agent awareness in system prompt` | `php artisan test --compact` |
| Wave 5 | `feat(tasks): add background and collaborative task execution with project knowledge flow` | `php artisan test --compact` |
| Wave 6 | `feat(agents): add conversational agent creation, context budget calculator, skill suggestions` | `php artisan test --compact` |
| Wave 7 | `feat(delegation): add task-based delegation, @mention routing, depth limiting` | `php artisan test --compact` |
| Wave 8 | `feat(proactive): add ProjectReviewAgent, scheduled check-ins, deadline reminders` | `php artisan test --compact` |
| Wave 9 | `feat(advanced): add project templates, messaging routing, AI-generated skills` | `php artisan test --compact` |

---

## Success Criteria

### Verification Commands
```bash
php artisan test --compact                           # ALL tests pass
php artisan tinker --execute="App\Models\Agent::count()"  # At least 1 (default agent)
php artisan tinker --execute="App\Models\Skill::where('source','built_in')->count()"  # 7 built-in skills
php artisan migrate:status                           # All migrations ran
vendor/bin/pint --dirty --format agent               # No formatting issues
```

### Final Checklist
- [ ] All "Must Have" items present
- [ ] All "Must NOT Have" items absent
- [ ] All 314+ existing tests still pass
- [ ] All new models have factories
- [ ] All factories used in at least one test
- [ ] DynamicAgent does NOT modify AegisAgent
- [ ] ToolRegistry unchanged — per-agent filtering in DynamicAgent
- [ ] Skills are text content only, no executable code
- [ ] Hard limits enforced: 10 agents, 5 skills/agent, 3000 tokens/skill
- [ ] Pint clean on all files
