<?php

namespace App\Desktop\Contracts;

interface DesktopBridge
{
    public function sendNotification(string $title, string $body): void;

    public function showDialog(string $title, string $message, string $type = 'info'): void;

    public function copyToClipboard(string $text): void;

    public function getClipboard(): string;

    public function runBackgroundProcess(string $command): void;
}
