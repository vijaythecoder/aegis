<?php

use App\Agent\AegisAgent;
use App\Agent\PlanningAgent;
use App\Agent\ReflectionAgent;
use App\Memory\EmbeddingService;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Rag\DocumentIngestionService;
use App\Rag\RetrievalService;
use App\Security\AuditLogger;
use App\Security\PermissionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

beforeEach(function () {
    Embeddings::fake();
    AegisAgent::fake(['Integration test response']);
    PlanningAgent::fake(['Step 1: analyze. Step 2: respond.']);
    ReflectionAgent::fake(['APPROVED: looks good']);
});

test('full agent prompt flow: user message to agent response', function () {
    $conversation = Conversation::factory()->create();

    Message::create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'What is Aegis?',
    ]);

    AegisAgent::fake(['Aegis is an AI agent platform.']);
    $result = app(AegisAgent::class)->prompt('What is Aegis?');

    expect($result->text)->toBe('Aegis is an AI agent platform.');

    AegisAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'What is Aegis?'));
});

test('rag flow: ingest document then retrieve relevant content', function () {
    $dims = (int) config('aegis.memory.embedding_dimensions', 768);
    $vector = array_fill(0, $dims, 0.5);

    $embeddingService = Mockery::mock(EmbeddingService::class)->shouldAllowMockingProtectedMethods();
    $embeddingService->shouldReceive('embed')->andReturn($vector);
    $embeddingService->shouldReceive('embedBatch')->andReturn([$vector]);
    $embeddingService->shouldReceive('dimensions')->andReturn($dims);
    app()->instance(EmbeddingService::class, $embeddingService);

    $tempFile = tempnam(sys_get_temp_dir(), 'aegis_test_');
    file_put_contents($tempFile, "# Aegis Architecture\n\nAegis uses a four-tier memory system with vector embeddings.");

    $service = app(DocumentIngestionService::class);
    $document = $service->ingest($tempFile);

    expect($document)->toBeInstanceOf(Document::class)
        ->and($document->status)->toBe('completed')
        ->and($document->chunk_count)->toBeGreaterThan(0);

    $retrieval = app(RetrievalService::class);
    $results = $retrieval->retrieve('memory system architecture');

    expect($results)->not->toBeEmpty();

    @unlink($tempFile);
});

test('audit logging creates signed tamper-proof chain', function () {
    $conversation = Conversation::factory()->create();
    $logger = app(AuditLogger::class);

    $entry1 = $logger->log('tool_call', 'file_read', ['path' => '/tmp/a'], 'allowed', $conversation->id);
    $entry2 = $logger->log('tool_call', 'file_write', ['path' => '/tmp/b'], 'allowed', $conversation->id);

    expect($entry1->signature)->not->toBeNull()
        ->and($entry2->previous_signature)->toBe($entry1->signature)
        ->and($logger->verify($entry1))->toBeTrue()
        ->and($logger->verify($entry2))->toBeTrue();

    $chain = $logger->verifyChain();
    expect($chain['valid'])->toBeTrue()
        ->and($chain['verified'])->toBe(2);
});

test('permission manager with capability tokens overrides default denial', function () {
    $manager = app(PermissionManager::class);

    $decision = $manager->check('shell', 'execute', ['command' => 'php -v']);
    expect($decision->name)->toBe('NeedsApproval');

    $manager->grantCapability('execute', '*', 'integration-test');

    $decision = $manager->check('shell', 'execute', ['command' => 'php -v']);
    expect($decision->name)->toBe('Allowed');
});

test('planning agent generates plan for complex queries', function () {
    PlanningAgent::fake(['Step 1: Research. Step 2: Summarize. Step 3: Present.']);

    $result = app(\App\Agent\PlanningStep::class)->generate('Build a REST API with authentication');

    expect($result)->toContain('Step 1');

    PlanningAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'REST API'));
});
