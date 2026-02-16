<?php

namespace App\Agent;

class AgentLoopResult
{
    /**
     * @param  array<int, string>  $steps
     */
    public function __construct(
        public readonly string $response,
        public readonly ?string $plan = null,
        public readonly ?string $review = null,
        public readonly array $steps = [],
        public readonly bool $usedPlanning = false,
        public readonly int $retries = 0,
    ) {}
}
