<?php

namespace App\Memory;

use Carbon\Carbon;

class TemporalParser
{
    /**
     * Parse temporal expressions from text and return a date range.
     *
     * @return array{from: Carbon, to: Carbon}|null
     */
    public function parse(string $text): ?array
    {
        $text = mb_strtolower($text);

        return $this->matchRelativeDays($text)
            ?? $this->matchRelativeWeeks($text)
            ?? $this->matchRelativeMonths($text)
            ?? $this->matchNamedPeriods($text);
    }

    /**
     * @return array{from: Carbon, to: Carbon}|null
     */
    private function matchRelativeDays(string $text): ?array
    {
        if (preg_match('/(\d+)\s+days?\s+ago/', $text, $matches)) {
            $days = (int) $matches[1];

            return [
                'from' => now()->subDays($days)->startOfDay(),
                'to' => now()->subDays($days)->endOfDay(),
            ];
        }

        if (str_contains($text, 'yesterday')) {
            return [
                'from' => now()->subDay()->startOfDay(),
                'to' => now()->subDay()->endOfDay(),
            ];
        }

        if (str_contains($text, 'today') || str_contains($text, 'earlier today')) {
            return [
                'from' => now()->startOfDay(),
                'to' => now(),
            ];
        }

        return null;
    }

    /**
     * @return array{from: Carbon, to: Carbon}|null
     */
    private function matchRelativeWeeks(string $text): ?array
    {
        if (preg_match('/(\d+)\s+weeks?\s+ago/', $text, $matches)) {
            $weeks = (int) $matches[1];

            return [
                'from' => now()->subWeeks($weeks)->startOfWeek(),
                'to' => now()->subWeeks($weeks)->endOfWeek(),
            ];
        }

        if (str_contains($text, 'last week')) {
            return [
                'from' => now()->subWeek()->startOfWeek(),
                'to' => now()->subWeek()->endOfWeek(),
            ];
        }

        if (str_contains($text, 'this week')) {
            return [
                'from' => now()->startOfWeek(),
                'to' => now(),
            ];
        }

        return null;
    }

    /**
     * @return array{from: Carbon, to: Carbon}|null
     */
    private function matchRelativeMonths(string $text): ?array
    {
        if (preg_match('/(\d+)\s+months?\s+ago/', $text, $matches)) {
            $months = (int) $matches[1];

            return [
                'from' => now()->subMonths($months)->startOfMonth(),
                'to' => now()->subMonths($months)->endOfMonth(),
            ];
        }

        if (str_contains($text, 'last month')) {
            return [
                'from' => now()->subMonth()->startOfMonth(),
                'to' => now()->subMonth()->endOfMonth(),
            ];
        }

        if (str_contains($text, 'this month')) {
            return [
                'from' => now()->startOfMonth(),
                'to' => now(),
            ];
        }

        return null;
    }

    /**
     * @return array{from: Carbon, to: Carbon}|null
     */
    private function matchNamedPeriods(string $text): ?array
    {
        if (str_contains($text, 'recently') || str_contains($text, 'the other day')) {
            return [
                'from' => now()->subDays(7)->startOfDay(),
                'to' => now(),
            ];
        }

        if (str_contains($text, 'a while ago') || str_contains($text, 'some time ago')) {
            return [
                'from' => now()->subMonths(3)->startOfDay(),
                'to' => now()->subWeek(),
            ];
        }

        return null;
    }
}
