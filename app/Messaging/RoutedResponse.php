<?php

namespace App\Messaging;

class RoutedResponse
{
    /**
     * @param  list<array{path: string, type: string}>  $attachments
     */
    public function __construct(
        public readonly string $text,
        public readonly array $attachments = [],
    ) {}

    public function hasAttachments(): bool
    {
        return $this->attachments !== [];
    }
}
