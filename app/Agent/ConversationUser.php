<?php

namespace App\Agent;

class ConversationUser
{
    public function __construct(
        public readonly string|int $id = 'local-user',
    ) {}
}
