<?php

namespace App\Agent;

use Illuminate\Support\Facades\Log;
use Throwable;

class AgentLoop
{
    private const MAX_RETRIES = 2;

    /** @var array<int, callable(string, string): void> */
    private array $stepListeners = [];

    public function __construct(
        private readonly PlanningStep $planningStep,
        private readonly ReflectionStep $reflectionStep,
        private readonly AegisAgent $executor,
    ) {}

    public function onStep(callable $listener): static
    {
        $this->stepListeners[] = $listener;

        return $this;
    }

    public function execute(string $prompt, int $conversationId, bool $withStorage = true): AgentLoopResult
    {
        if (! $this->requiresPlanning($prompt)) {
            return $this->directExecution($prompt, $conversationId, $withStorage);
        }

        return $this->plannedExecution($prompt, $conversationId, withStorage: $withStorage);
    }

    public function requiresPlanning(string $prompt): bool
    {
        return $this->planningStep->isComplex($prompt);
    }

    private function directExecution(string $prompt, int $conversationId, bool $withStorage = true): AgentLoopResult
    {
        $this->emitStep('executing', 'Processing your request...');

        $response = $this->executor
            ->forConversation($conversationId, $withStorage)
            ->prompt($prompt);

        return new AgentLoopResult(
            response: $response->text,
            usedPlanning: false,
        );
    }

    private function plannedExecution(string $prompt, int $conversationId, int $attempt = 0, bool $withStorage = true): AgentLoopResult
    {
        $this->emitStep('planning', 'Analyzing request and creating plan...');

        $plan = $this->planningStep->generate($prompt);

        if ($plan === null) {
            return $this->directExecution($prompt, $conversationId, $withStorage);
        }

        $this->emitStep('executing', 'Executing plan...');

        $executionPrompt = implode("\n\n", [
            $prompt,
            "Follow this execution plan:\n{$plan}",
            'Execute each step thoroughly. Provide the complete final result to the user.',
        ]);

        $response = $this->executor
            ->forConversation($conversationId, $withStorage)
            ->prompt($executionPrompt);

        $reflection = $this->reflectionStep->reflect($prompt, $response->text);

        if (! $reflection->approved && $attempt < self::MAX_RETRIES) {
            $this->emitStep('retrying', 'Improving response (attempt '.($attempt + 2).')...');

            return $this->retry($prompt, $response->text, $reflection->feedback, $conversationId, $attempt + 1, $withStorage);
        }

        return new AgentLoopResult(
            response: $response->text,
            plan: $plan,
            review: $reflection->feedback,
            usedPlanning: true,
            retries: $attempt,
        );
    }

    private function retry(string $prompt, string $previousResponse, ?string $feedback, int $conversationId, int $attempt, bool $withStorage = true): AgentLoopResult
    {
        $revisionPrompt = implode("\n\n", [
            "Original request: {$prompt}",
            "Your previous response had issues:\n{$feedback}",
            "Previous response for reference:\n{$previousResponse}",
            'Please provide an improved, complete response addressing the feedback above.',
        ]);

        try {
            $response = $this->executor
                ->forConversation($conversationId, $withStorage)
                ->prompt($revisionPrompt);

            $reflection = $this->reflectionStep->reflect($prompt, $response->text);

            return new AgentLoopResult(
                response: $response->text,
                review: $reflection->feedback,
                usedPlanning: true,
                retries: $attempt,
            );
        } catch (Throwable $e) {
            Log::debug('AgentLoop: retry failed, returning previous response', [
                'error' => $e->getMessage(),
                'attempt' => $attempt,
            ]);

            return new AgentLoopResult(
                response: $previousResponse,
                usedPlanning: true,
                retries: $attempt,
            );
        }
    }

    private function emitStep(string $phase, string $detail): void
    {
        foreach ($this->stepListeners as $listener) {
            try {
                $listener($phase, $detail);
            } catch (Throwable) {
            }
        }
    }
}
