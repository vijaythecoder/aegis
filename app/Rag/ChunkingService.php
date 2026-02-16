<?php

namespace App\Rag;

use Illuminate\Support\Facades\Log;

class ChunkingService
{
    private const CODE_EXTENSIONS = ['php', 'js', 'ts', 'jsx', 'tsx', 'py', 'rb', 'go', 'rs', 'java', 'c', 'cpp', 'cs'];

    private const MARKDOWN_EXTENSIONS = ['md', 'mdx'];

    /** @return array<int, array{content: string, metadata: array{file_path: string, start_line: int, end_line: int, chunk_index: int, file_type: string}}> */
    public function chunk(string $path): array
    {
        if (! file_exists($path) || ! is_readable($path)) {
            return [];
        }

        $fileSizeBytes = filesize($path);
        $maxBytes = config('aegis.rag.max_file_size_mb', 10) * 1024 * 1024;

        if ($fileSizeBytes > $maxBytes) {
            Log::warning('ChunkingService: file exceeds max size', ['path' => $path, 'size' => $fileSizeBytes]);

            return [];
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $content = file_get_contents($path);

        if ($content === false || trim($content) === '') {
            return [];
        }

        $fileType = $this->detectFileType($extension);

        return match ($fileType) {
            'php' => $this->chunkPhpCode($content, $path),
            'code' => $this->chunkGenericCode($content, $path, $extension),
            'markdown' => $this->chunkMarkdown($content, $path),
            default => $this->chunkText($content, $path),
        };
    }

    private function detectFileType(string $extension): string
    {
        if ($extension === 'php') {
            return 'php';
        }

        if (in_array($extension, self::CODE_EXTENSIONS, true)) {
            return 'code';
        }

        if (in_array($extension, self::MARKDOWN_EXTENSIONS, true)) {
            return 'markdown';
        }

        return 'text';
    }

    /** @return array<int, array{content: string, metadata: array{file_path: string, start_line: int, end_line: int, chunk_index: int, file_type: string}}> */
    private function chunkPhpCode(string $content, string $path): array
    {
        $lines = explode("\n", $content);
        $boundaries = $this->findPhpBoundaries($lines);

        return $this->buildChunksFromBoundaries($lines, $boundaries, $path, 'php');
    }

    /** @return array<int, array{start: int, end: int}> */
    private function findPhpBoundaries(array $lines): array
    {
        $boundaries = [];
        $currentStart = 0;
        $braceDepth = 0;
        $inBlock = false;

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);

            $isBlockStart = (bool) preg_match('/^(namespace|class|interface|trait|enum|function|abstract\s+class|final\s+class)\s/', $trimmed)
                || (bool) preg_match('/^\s*(public|protected|private|static)\s+(function|static\s+function)\s/', $line);

            if ($isBlockStart && ! $inBlock) {
                if ($i > $currentStart && $this->hasNonEmptyContent($lines, $currentStart, $i - 1)) {
                    $boundaries[] = ['start' => $currentStart, 'end' => $i - 1];
                }
                $currentStart = $i;
                $inBlock = true;
                $braceDepth = 0;
            }

            $braceDepth += substr_count($line, '{') - substr_count($line, '}');

            if ($inBlock && $braceDepth <= 0 && str_contains($line, '}')) {
                $boundaries[] = ['start' => $currentStart, 'end' => $i];
                $currentStart = $i + 1;
                $inBlock = false;
                $braceDepth = 0;
            }
        }

        if ($currentStart < count($lines) && $this->hasNonEmptyContent($lines, $currentStart, count($lines) - 1)) {
            $boundaries[] = ['start' => $currentStart, 'end' => count($lines) - 1];
        }

        if (empty($boundaries) && ! empty($lines)) {
            $boundaries[] = ['start' => 0, 'end' => count($lines) - 1];
        }

        return $boundaries;
    }

    /** @return array<int, array{content: string, metadata: array{file_path: string, start_line: int, end_line: int, chunk_index: int, file_type: string}}> */
    private function chunkGenericCode(string $content, string $path, string $extension): array
    {
        $lines = explode("\n", $content);
        $boundaries = $this->findGenericCodeBoundaries($lines, $extension);

        return $this->buildChunksFromBoundaries($lines, $boundaries, $path, 'code');
    }

    /** @return array<int, array{start: int, end: int}> */
    private function findGenericCodeBoundaries(array $lines, string $extension): array
    {
        $patterns = match ($extension) {
            'py' => ['/^(class|def|async\s+def)\s/'],
            'js', 'jsx', 'ts', 'tsx' => ['/^(export\s+)?(function|class|const|let|var)\s/', '/^(export\s+)?default\s/'],
            'rb' => ['/^(class|module|def)\s/'],
            'go' => ['/^(func|type)\s/'],
            'rs' => ['/^(pub\s+)?(fn|struct|enum|impl|trait|mod)\s/'],
            'java', 'cs' => ['/^(public|private|protected|static|abstract|final)?\s*(class|interface|enum|void|int|String|static)\s/'],
            default => ['/^(function|class|def|fn)\s/'],
        };

        $boundaries = [];
        $currentStart = 0;

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            $isBlockStart = false;

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $trimmed)) {
                    $isBlockStart = true;
                    break;
                }
            }

            if ($isBlockStart && $i > $currentStart) {
                if ($this->hasNonEmptyContent($lines, $currentStart, $i - 1)) {
                    $boundaries[] = ['start' => $currentStart, 'end' => $i - 1];
                }
                $currentStart = $i;
            }
        }

        if ($currentStart < count($lines) && $this->hasNonEmptyContent($lines, $currentStart, count($lines) - 1)) {
            $boundaries[] = ['start' => $currentStart, 'end' => count($lines) - 1];
        }

        if (empty($boundaries) && ! empty($lines)) {
            $boundaries[] = ['start' => 0, 'end' => count($lines) - 1];
        }

        return $boundaries;
    }

    /** @return array<int, array{content: string, metadata: array{file_path: string, start_line: int, end_line: int, chunk_index: int, file_type: string}}> */
    private function chunkMarkdown(string $content, string $path): array
    {
        $lines = explode("\n", $content);
        $sections = [];
        $currentStart = 0;

        foreach ($lines as $i => $line) {
            if (preg_match('/^#{1,6}\s/', $line) && $i > $currentStart) {
                if ($this->hasNonEmptyContent($lines, $currentStart, $i - 1)) {
                    $sections[] = ['start' => $currentStart, 'end' => $i - 1];
                }
                $currentStart = $i;
            }
        }

        if ($currentStart < count($lines) && $this->hasNonEmptyContent($lines, $currentStart, count($lines) - 1)) {
            $sections[] = ['start' => $currentStart, 'end' => count($lines) - 1];
        }

        if (empty($sections) && ! empty($lines)) {
            $sections[] = ['start' => 0, 'end' => count($lines) - 1];
        }

        $chunkSize = (int) config('aegis.rag.chunk_size', 512);
        $chunks = [];
        $chunkIndex = 0;

        foreach ($sections as $section) {
            $sectionContent = implode("\n", array_slice($lines, $section['start'], $section['end'] - $section['start'] + 1));
            $wordCount = str_word_count($sectionContent);

            if ($wordCount > $chunkSize) {
                $subChunks = $this->splitByWordCount($sectionContent, $chunkSize, $section['start']);
                foreach ($subChunks as $sub) {
                    $chunks[] = [
                        'content' => $sub['content'],
                        'metadata' => [
                            'file_path' => $path,
                            'start_line' => $sub['start_line'],
                            'end_line' => $sub['end_line'],
                            'chunk_index' => $chunkIndex++,
                            'file_type' => 'markdown',
                        ],
                    ];
                }
            } else {
                $chunks[] = [
                    'content' => $sectionContent,
                    'metadata' => [
                        'file_path' => $path,
                        'start_line' => $section['start'] + 1,
                        'end_line' => $section['end'] + 1,
                        'chunk_index' => $chunkIndex++,
                        'file_type' => 'markdown',
                    ],
                ];
            }
        }

        return $chunks;
    }

    /** @return array<int, array{content: string, metadata: array{file_path: string, start_line: int, end_line: int, chunk_index: int, file_type: string}}> */
    private function chunkText(string $content, string $path): array
    {
        $chunkSize = (int) config('aegis.rag.chunk_size', 512);
        $overlapWords = (int) config('aegis.rag.chunk_overlap', 50);

        $sentences = preg_split('/(?<=[.!?])\s+/', $content, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($sentences)) {
            return [];
        }

        $chunks = [];
        $chunkIndex = 0;
        $currentWords = [];
        $lines = explode("\n", $content);
        $totalLines = count($lines);

        $allWords = [];
        foreach ($sentences as $sentence) {
            $words = explode(' ', $sentence);
            foreach ($words as $word) {
                $allWords[] = $word;
            }
        }

        $totalWords = count($allWords);
        $pos = 0;

        while ($pos < $totalWords) {
            $end = min($pos + $chunkSize, $totalWords);
            $chunkWords = array_slice($allWords, $pos, $end - $pos);
            $chunkContent = implode(' ', $chunkWords);

            $startLine = $this->estimateLineNumber($content, $pos, $totalWords, $totalLines);
            $endLine = $this->estimateLineNumber($content, $end - 1, $totalWords, $totalLines);

            $chunks[] = [
                'content' => $chunkContent,
                'metadata' => [
                    'file_path' => $path,
                    'start_line' => max(1, $startLine),
                    'end_line' => max(1, $endLine),
                    'chunk_index' => $chunkIndex++,
                    'file_type' => 'text',
                ],
            ];

            $step = $chunkSize - $overlapWords;
            if ($step < 1) {
                $step = 1;
            }
            $pos += $step;
        }

        return $chunks;
    }

    /** @return array<int, array{content: string, metadata: array{file_path: string, start_line: int, end_line: int, chunk_index: int, file_type: string}}> */
    private function buildChunksFromBoundaries(array $lines, array $boundaries, string $path, string $fileType): array
    {
        $chunkSize = (int) config('aegis.rag.chunk_size', 512);
        $chunks = [];
        $chunkIndex = 0;
        $buffer = '';
        $bufferStartLine = null;
        $bufferEndLine = null;

        foreach ($boundaries as $boundary) {
            $sectionContent = implode("\n", array_slice($lines, $boundary['start'], $boundary['end'] - $boundary['start'] + 1));
            $sectionWords = str_word_count($sectionContent);

            if ($bufferStartLine === null) {
                $bufferStartLine = $boundary['start'] + 1;
            }

            $currentBufferWords = str_word_count($buffer);

            if ($currentBufferWords + $sectionWords > $chunkSize && $buffer !== '') {
                $chunks[] = [
                    'content' => trim($buffer),
                    'metadata' => [
                        'file_path' => $path,
                        'start_line' => $bufferStartLine,
                        'end_line' => $bufferEndLine ?? $boundary['start'],
                        'chunk_index' => $chunkIndex++,
                        'file_type' => $fileType,
                    ],
                ];
                $buffer = $sectionContent;
                $bufferStartLine = $boundary['start'] + 1;
                $bufferEndLine = $boundary['end'] + 1;
            } else {
                $buffer .= ($buffer !== '' ? "\n\n" : '').$sectionContent;
                $bufferEndLine = $boundary['end'] + 1;
            }
        }

        if (trim($buffer) !== '') {
            $chunks[] = [
                'content' => trim($buffer),
                'metadata' => [
                    'file_path' => $path,
                    'start_line' => $bufferStartLine ?? 1,
                    'end_line' => $bufferEndLine ?? 1,
                    'chunk_index' => $chunkIndex++,
                    'file_type' => $fileType,
                ],
            ];
        }

        return $chunks;
    }

    private function hasNonEmptyContent(array $lines, int $start, int $end): bool
    {
        for ($i = $start; $i <= $end && $i < count($lines); $i++) {
            if (trim($lines[$i]) !== '') {
                return true;
            }
        }

        return false;
    }

    private function estimateLineNumber(string $content, int $wordPos, int $totalWords, int $totalLines): int
    {
        if ($totalWords === 0) {
            return 1;
        }

        $ratio = $wordPos / $totalWords;

        return (int) ceil($ratio * $totalLines);
    }

    /** @return array<int, array{content: string, start_line: int, end_line: int}> */
    private function splitByWordCount(string $content, int $maxWords, int $baseLineOffset): array
    {
        $lines = explode("\n", $content);
        $result = [];
        $currentChunk = [];
        $currentWordCount = 0;
        $startLine = 0;

        foreach ($lines as $i => $line) {
            $lineWords = str_word_count($line);

            if ($currentWordCount + $lineWords > $maxWords && ! empty($currentChunk)) {
                $result[] = [
                    'content' => implode("\n", $currentChunk),
                    'start_line' => $baseLineOffset + $startLine + 1,
                    'end_line' => $baseLineOffset + $i,
                ];
                $currentChunk = [$line];
                $currentWordCount = $lineWords;
                $startLine = $i;
            } else {
                $currentChunk[] = $line;
                $currentWordCount += $lineWords;
            }
        }

        if (! empty($currentChunk)) {
            $result[] = [
                'content' => implode("\n", $currentChunk),
                'start_line' => $baseLineOffset + $startLine + 1,
                'end_line' => $baseLineOffset + count($lines),
            ];
        }

        return $result;
    }
}
