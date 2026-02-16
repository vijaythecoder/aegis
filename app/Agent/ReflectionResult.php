<?php

namespace App\Agent;

readonly class ReflectionResult
{
    public function __construct(
        public bool $approved,
        public ?string $feedback = null,
    ) {}

    public static function approved(): self
    {
        return new self(approved: true);
    }

    public static function needsRevision(string $feedback): self
    {
        return new self(approved: false, feedback: $feedback);
    }
}
