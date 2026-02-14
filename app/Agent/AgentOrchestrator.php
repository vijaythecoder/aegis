<?php

namespace App\Agent;

use App\Agent\Contracts\ToolInterface;
use App\Enums\AuditLogResult;
use App\Enums\MessageRole;
use App\Enums\ToolPermissionLevel;
use App\Events\ApprovalRequest;
use App\Events\ApprovalResponse;
use App\Models\Conversation;
use App\Models\Message;
use App\Security\AuditLogger;
use App\Security\PermissionDecision;
use App\Security\PermissionManager;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool as PrismToolFacade;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Text\Response as PrismTextResponse;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult as PrismToolResult;
use Throwable;

class AgentOrchestrator
{
    private array $tools;

    private PermissionManager $permissionManager;

    private AuditLogger $auditLogger;

    private ?Closure $approvalResolver;

    private ProviderManager $providerManager;

    private ModelCapabilities $modelCapabilities;

    public function __construct(
        private readonly SystemPromptBuilder $promptBuilder,
        private readonly ContextManager $contextManager,
        iterable $tools = [],
        private readonly ?Closure $llmInvoker = null,
        ?PermissionManager $permissionManager = null,
        ?AuditLogger $auditLogger = null,
        ?Closure $approvalResolver = null,
        ?ProviderManager $providerManager = null,
        ?ModelCapabilities $modelCapabilities = null,
    ) {
        $this->tools = collect($tools)
            ->filter(fn ($tool): bool => $tool instanceof ToolInterface)
            ->mapWithKeys(fn (ToolInterface $tool): array => [$tool->name() => $tool])
            ->all();

        $this->permissionManager = $permissionManager ?? app(PermissionManager::class);
        $this->auditLogger = $auditLogger ?? app(AuditLogger::class);
        $this->approvalResolver = $approvalResolver;
        $this->providerManager = $providerManager ?? app(ProviderManager::class);
        $this->modelCapabilities = $modelCapabilities ?? app(ModelCapabilities::class);
    }

    public function toolNames(): array
    {
        return array_values(array_keys($this->tools));
    }

    public function respond(string $message, int $conversationId, ?string $provider = null, ?string $model = null): string
    {
        $conversation = Conversation::query()->findOrFail($conversationId);
        [$resolvedProvider, $resolvedModel] = $this->providerManager->resolve($provider, $model);

        $this->storeUserMessage($conversation, $message);

        $systemPrompt = $this->promptBuilder->build($conversation);
        $history = $conversation->messages()->oldest('id')->get();
        $normalizedHistory = $history->map(fn (Message $row): array => [
            'role' => $row->role->value,
            'content' => $row->content,
        ])->all();

        $memories = $conversation->memories()
            ->orderByDesc('confidence')
            ->limit(10)
            ->get()
            ->map(fn ($memory): string => sprintf('%s: %s', $memory->key, $memory->value))
            ->all();

        $trimmedHistory = $this->contextManager->truncateMessages(
            $systemPrompt,
            $normalizedHistory,
            null,
            $conversation->summary,
            $memories,
            $resolvedProvider,
            $resolvedModel,
        );

        $historyOnly = array_values(array_filter(
            $trimmedHistory,
            fn (array $message): bool => (($message['role'] ?? 'user') !== 'system')
        ));

        if ($conversation->summary === null) {
            $droppedCount = max(0, count($normalizedHistory) - count($historyOnly));
            $droppedMessages = $droppedCount > 0 ? array_slice($normalizedHistory, 0, $droppedCount) : [];
            $summarizer = app(ConversationSummarizer::class);

            if ($summarizer->shouldSummarize($droppedMessages)) {
                $summary = $summarizer->summarize($droppedMessages);

                if ($summary !== '') {
                    $summarizer->updateConversationSummary($conversation->id, $summary);
                    $conversation->summary = $summary;
                    $trimmedHistory = $this->contextManager->truncateMessages(
                        $systemPrompt,
                        $normalizedHistory,
                        null,
                        $summary,
                        $memories,
                        $resolvedProvider,
                        $resolvedModel,
                    );
                }
            }
        }

        $prismMessages = $this->contextManager->toPrismMessages($trimmedHistory);

        $assistantText = $this->runAgentLoop($systemPrompt, $prismMessages, $conversation, $resolvedProvider, $resolvedModel);

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => $assistantText,
            'tokens_used' => $this->contextManager->estimateTokens($assistantText),
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        return $assistantText;
    }

    public function respondStreaming(string $message, int $conversationId, StreamBuffer $buffer, ?Closure $onChunk = null, ?string $provider = null, ?string $model = null): string
    {
        $conversation = Conversation::query()->findOrFail($conversationId);
        [$resolvedProvider, $resolvedModel] = $this->providerManager->resolve($provider, $model);

        $this->storeUserMessage($conversation, $message);

        $systemPrompt = $this->promptBuilder->build($conversation);
        $history = $conversation->messages()->oldest('id')->get();
        $normalizedHistory = $history->map(fn (Message $row): array => [
            'role' => $row->role->value,
            'content' => $row->content,
        ])->all();

        $trimmedHistory = $this->contextManager->truncateMessages($systemPrompt, $normalizedHistory, null, null, null, $resolvedProvider, $resolvedModel);
        $prismMessages = $this->contextManager->toPrismMessages($trimmedHistory);

        $buffer->clear();
        $buffer->start();

        $assistantText = '';

        try {
            if ($this->llmInvoker !== null) {
                $response = $this->invokeLlmWithRetry($systemPrompt, $prismMessages, (int) config('aegis.agent.max_steps', 10), $resolvedProvider, $resolvedModel);
                $assistantText = $this->simulateStreamingText($response->text, $buffer, $onChunk);
            } else {
                $assistantText = $this->invokeLlmStream($systemPrompt, $prismMessages, $buffer, $onChunk, $resolvedProvider, $resolvedModel);
            }
        } finally {
            if ($buffer->isActive()) {
                $buffer->complete();
            }
        }

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::Assistant,
            'content' => $assistantText,
            'tool_result' => [
                'is_complete' => ! $buffer->isCancelled(),
                'cancelled' => $buffer->isCancelled(),
                'streamed' => true,
            ],
            'tokens_used' => $this->contextManager->estimateTokens($assistantText),
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        return $assistantText;
    }

    private function runAgentLoop(string $systemPrompt, array $messages, Conversation $conversation, string $provider, string $model): string
    {
        $maxSteps = (int) config('aegis.agent.max_steps', 10);

        for ($step = 0; $step < $maxSteps; $step++) {
            $response = $this->invokeLlmWithRetry($systemPrompt, $messages, $maxSteps, $provider, $model);

            if ($response->toolCalls === []) {
                return $response->text;
            }

            $messages[] = new AssistantMessage('', $response->toolCalls);
            $messages[] = new ToolResultMessage($this->executeToolCalls($conversation, $response->toolCalls));
        }

        throw new \RuntimeException('Maximum tool execution steps reached.');
    }

    private function invokeLlmWithRetry(string $systemPrompt, array $messages, int $maxSteps, string $provider, string $model): PrismTextResponse
    {
        if ($this->llmInvoker === null) {
            return $this->providerManager->failover($provider, fn (string $activeProvider): PrismTextResponse => $this->invokeLlmWithProviderRetries(
                $systemPrompt,
                $messages,
                $maxSteps,
                $activeProvider,
                $activeProvider === $provider ? $model : $this->providerManager->resolve($activeProvider, null)[1],
            ));
        }

        return $this->invokeLlmWithProviderRetries($systemPrompt, $messages, $maxSteps, $provider, $model);
    }

    private function invokeLlmWithProviderRetries(string $systemPrompt, array $messages, int $maxSteps, string $provider, string $model): PrismTextResponse
    {
        $maxRetries = (int) config('aegis.agent.max_retries', 3);
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->invokeLlm($systemPrompt, $messages, $maxSteps, $provider, $model);
            } catch (Throwable $exception) {
                $lastException = $exception;

                if ($attempt === $maxRetries) {
                    break;
                }

                usleep($attempt * 100000);
            }
        }

        throw $lastException ?? new \RuntimeException('Unknown LLM invocation failure.');
    }

    private function invokeLlm(string $systemPrompt, array $messages, int $maxSteps, string $provider, string $model): PrismTextResponse
    {
        if ($this->llmInvoker !== null) {
            return ($this->llmInvoker)($systemPrompt, $messages, $this->tools, $maxSteps);
        }

        return Prism::text()
            ->using($provider, $model)
            ->withClientOptions(['timeout' => (int) config('aegis.agent.timeout', 120)])
            ->withSystemPrompt($systemPrompt)
            ->withMessages($messages)
            ->withTools($this->toolDefinitionsFor($provider, $model))
            ->withMaxSteps($maxSteps)
            ->asText();
    }

    private function invokeLlmStream(string $systemPrompt, array $messages, StreamBuffer $buffer, ?Closure $onChunk = null, string $provider = 'anthropic', string $model = 'claude-sonnet-4-20250514'): string
    {
        $fullText = '';
        $maxSteps = (int) config('aegis.agent.max_steps', 10);

        $stream = Prism::text()
            ->using($provider, $model)
            ->withClientOptions(['timeout' => (int) config('aegis.agent.timeout', 120)])
            ->withSystemPrompt($systemPrompt)
            ->withMessages($messages)
            ->withTools($this->toolDefinitionsFor($provider, $model))
            ->withMaxSteps($maxSteps)
            ->asStream();

        foreach ($stream as $event) {
            if ($buffer->isCancelled()) {
                break;
            }

            if (! $event instanceof TextDeltaEvent) {
                continue;
            }

            if ($event->delta === '') {
                continue;
            }

            $buffer->append($event->delta);
            $fullText .= $event->delta;

            if ($onChunk !== null) {
                $onChunk($event->delta, $fullText, $buffer);
            }
        }

        return $fullText;
    }

    private function simulateStreamingText(string $text, StreamBuffer $buffer, ?Closure $onChunk = null): string
    {
        $fullText = '';
        $chunks = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($chunks as $chunk) {
            if ($buffer->isCancelled()) {
                break;
            }

            $buffer->append($chunk);
            $fullText .= $chunk;

            if ($onChunk !== null) {
                $onChunk($chunk, $fullText, $buffer);
            }

            usleep(10000);
        }

        return $fullText;
    }

    private function storeUserMessage(Conversation $conversation, string $message): void
    {
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => MessageRole::User,
            'content' => $message,
            'tokens_used' => $this->contextManager->estimateTokens($message),
        ]);
    }

    private function executeToolCalls(Conversation $conversation, array $toolCalls): array
    {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            $result = $this->executeSingleToolCall($conversation, $toolCall);
            $args = $toolCall->arguments();

            Message::query()->create([
                'conversation_id' => $conversation->id,
                'role' => MessageRole::Tool,
                'content' => is_scalar($result->output)
                    ? (string) $result->output
                    : json_encode($result->output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'tool_name' => $toolCall->name,
                'tool_call_id' => $toolCall->id,
                'tool_result' => [
                    'success' => $result->success,
                    'output' => $result->output,
                    'error' => $result->error,
                ],
                'tokens_used' => $this->contextManager->estimateTokens(json_encode($args) ?: ''),
            ]);

            $results[] = new PrismToolResult(
                toolCallId: $toolCall->id,
                toolName: $toolCall->name,
                args: $args,
                result: $result->success
                    ? $result->output
                    : ['error' => $result->error ?? 'Tool execution failed'],
            );
        }

        return $results;
    }

    private function executeSingleToolCall(Conversation $conversation, ToolCall $toolCall): ToolResult
    {
        $arguments = $toolCall->arguments();
        $toolName = $toolCall->name;

        $this->auditLogger->log(
            action: 'tool.request',
            toolName: $toolName,
            parameters: $arguments,
            result: AuditLogResult::Pending->value,
            conversationId: $conversation->id,
        );

        $tool = $this->tools[$toolCall->name] ?? null;

        if (! $tool instanceof ToolInterface) {
            $this->auditLogger->log(
                action: 'tool.error',
                toolName: $toolName,
                parameters: $arguments,
                result: AuditLogResult::Error->value,
                conversationId: $conversation->id,
            );

            return new ToolResult(false, null, "Unknown tool: {$toolCall->name}");
        }

        $scope = 'conversation:'.$conversation->id;
        $permission = $tool->requiredPermission();
        $decision = $this->permissionManager->check($toolName, $permission, [
            ...$arguments,
            'scope' => $scope,
        ]);

        if ($decision === PermissionDecision::NeedsApproval) {
            $request = new ApprovalRequest($toolName, $permission, $arguments);
            event($request);

            $response = $this->awaitApprovalResponse($request, $scope);
            if ($response === null || $response->decision !== 'allow') {
                $this->auditLogger->log(
                    action: 'tool.denied',
                    toolName: $toolName,
                    parameters: $arguments,
                    result: AuditLogResult::Denied->value,
                    conversationId: $conversation->id,
                );

                return new ToolResult(false, null, 'Tool execution denied by approval policy.');
            }

            if ($response->remember) {
                $this->permissionManager->remember($toolName, ToolPermissionLevel::Allow, $scope);
            }

            $decision = PermissionDecision::Allowed;
        }

        if ($decision === PermissionDecision::Denied) {
            $this->auditLogger->log(
                action: 'tool.denied',
                toolName: $toolName,
                parameters: $arguments,
                result: AuditLogResult::Denied->value,
                conversationId: $conversation->id,
            );

            return new ToolResult(false, null, 'Tool execution denied by security policy.');
        }

        $this->auditLogger->log(
            action: 'tool.allowed',
            toolName: $toolName,
            parameters: $arguments,
            result: AuditLogResult::Allowed->value,
            conversationId: $conversation->id,
        );

        try {
            $result = $tool->execute($arguments);

            $this->auditLogger->log(
                action: $result->success ? 'tool.executed' : 'tool.error',
                toolName: $toolName,
                parameters: [
                    ...$arguments,
                    'tool_result' => [
                        'success' => $result->success,
                        'output' => $result->output,
                        'error' => $result->error,
                    ],
                ],
                result: $result->success ? AuditLogResult::Allowed->value : AuditLogResult::Error->value,
                conversationId: $conversation->id,
            );

            return $result;
        } catch (Throwable $exception) {
            $this->auditLogger->log(
                action: 'tool.error',
                toolName: $toolName,
                parameters: [
                    ...$arguments,
                    'exception' => $exception->getMessage(),
                ],
                result: AuditLogResult::Error->value,
                conversationId: $conversation->id,
            );

            return new ToolResult(false, null, $exception->getMessage());
        }
    }

    private function awaitApprovalResponse(ApprovalRequest $request, string $scope): ?ApprovalResponse
    {
        $timeout = (int) config('aegis.security.approval_timeout', 60);

        if ($this->approvalResolver instanceof Closure) {
            $resolved = ($this->approvalResolver)($request, $timeout, $scope);

            return $this->normalizeApprovalResponse($request->requestId, $resolved);
        }

        $cacheKey = 'approval-response:'.$request->requestId;
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $payload = Cache::pull($cacheKey);

            if (is_array($payload)) {
                return $this->normalizeApprovalResponse($request->requestId, $payload);
            }

            usleep(200000);
        }

        return null;
    }

    private function normalizeApprovalResponse(string $requestId, mixed $resolved): ?ApprovalResponse
    {
        if ($resolved instanceof ApprovalResponse) {
            return $resolved;
        }

        if (is_array($resolved)) {
            $decision = (string) ($resolved['decision'] ?? 'deny');
            $remember = (bool) ($resolved['remember'] ?? false);

            return new ApprovalResponse($requestId, $decision, $remember);
        }

        if (is_string($resolved)) {
            return new ApprovalResponse($requestId, $resolved, false);
        }

        return null;
    }

    private function toPrismTools(): array
    {
        return array_map(fn (ToolInterface $tool): Tool => $this->toPrismTool($tool), array_values($this->tools));
    }

    private function toolDefinitionsFor(string $provider, string $model): array
    {
        if (! $this->modelCapabilities->supportsTools($provider, $model)) {
            return [];
        }

        return $this->toPrismTools();
    }

    private function toPrismTool(ToolInterface $tool): Tool
    {
        $prismTool = PrismToolFacade::as($tool->name())
            ->for($tool->description())
            ->using(fn (): string => 'Handled by Aegis orchestrator');

        $schema = $tool->parameters();
        $required = Arr::wrap($schema['required'] ?? []);
        $properties = Arr::get($schema, 'properties', []);

        foreach ($properties as $name => $definition) {
            $propertyType = (string) Arr::get($definition, 'type', 'string');
            $description = (string) Arr::get($definition, 'description', $name);
            $isRequired = in_array($name, $required, true);

            if ($propertyType === 'integer' || $propertyType === 'number') {
                $prismTool->withNumberParameter($name, $description, $isRequired);

                continue;
            }

            if ($propertyType === 'boolean') {
                $prismTool->withBooleanParameter($name, $description, $isRequired);

                continue;
            }

            $prismTool->withStringParameter($name, $description, $isRequired);
        }

        return $prismTool;
    }
}
