<?php

namespace App\Desktop;

use App\Desktop\Contracts\DesktopBridge;
use Native\Laravel\Facades\Alert;
use Native\Laravel\Facades\Clipboard;
use Native\Laravel\Facades\Notification;
use Native\Laravel\Facades\Shell;

class ElectronDesktopBridge implements DesktopBridge
{
    public function sendNotification(string $title, string $body): void
    {
        Notification::title($title)->message($body)->show();
    }

    public function showDialog(string $title, string $message, string $type = 'info'): void
    {
        Alert::new()->type($type)->title($title)->show($message);
    }

    public function copyToClipboard(string $text): void
    {
        Clipboard::text($text);
    }

    public function getClipboard(): string
    {
        return Clipboard::text() ?? '';
    }

    public function runBackgroundProcess(string $command): void
    {
        Shell::openExternal($command);
    }
}
