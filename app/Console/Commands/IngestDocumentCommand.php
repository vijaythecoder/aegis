<?php

namespace App\Console\Commands;

use App\Rag\DocumentIngestionService;
use Illuminate\Console\Command;

class IngestDocumentCommand extends Command
{
    protected $signature = 'aegis:ingest {path : Path to file or directory to ingest}';

    protected $description = 'Ingest a document or directory into the knowledge base';

    public function handle(DocumentIngestionService $service): int
    {
        $path = $this->argument('path');

        if (! file_exists($path)) {
            $this->error("Path not found: {$path}");

            return self::FAILURE;
        }

        if (is_dir($path)) {
            return $this->ingestDirectory($service, $path);
        }

        return $this->ingestFile($service, $path);
    }

    private function ingestFile(DocumentIngestionService $service, string $path): int
    {
        $this->info("Ingesting: {$path}");

        $document = $service->ingest($path);

        if (! $document) {
            $this->error("Failed to ingest: {$path}");

            return self::FAILURE;
        }

        $this->info("Status: {$document->status} | Chunks: {$document->chunk_count}");

        return $document->status === 'completed' ? self::SUCCESS : self::FAILURE;
    }

    private function ingestDirectory(DocumentIngestionService $service, string $dir): int
    {
        $extensions = ['php', 'js', 'ts', 'py', 'md', 'txt', 'jsx', 'tsx', 'rb', 'go', 'rs'];
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                $files[] = $file->getPathname();
            }
        }

        if (empty($files)) {
            $this->warn('No supported files found.');

            return self::SUCCESS;
        }

        $this->info('Found '.count($files).' files to ingest.');
        $failed = 0;

        foreach ($files as $filePath) {
            $result = $this->ingestFile($service, $filePath);
            if ($result !== self::SUCCESS) {
                $failed++;
            }
        }

        if ($failed > 0) {
            $this->warn("{$failed} file(s) failed to ingest.");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
