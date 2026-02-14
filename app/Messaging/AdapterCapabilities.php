<?php

namespace App\Messaging;

class AdapterCapabilities
{
    public function __construct(
        public readonly bool $supportsMedia = false,
        public readonly bool $supportsButtons = false,
        public readonly bool $supportsMarkdown = false,
        public readonly int $maxMessageLength = 4096,
        public readonly bool $supportsEditing = false,
    ) {}
}
