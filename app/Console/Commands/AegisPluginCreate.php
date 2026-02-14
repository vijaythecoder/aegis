<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisPluginCreate extends Command
{
    protected $signature = 'aegis:plugin:create {name}';

    protected $description = 'Scaffold a new local plugin';

    public function handle(): int
    {
        $name = strtolower((string) $this->argument('name'));
        $pluginPath = rtrim((string) config('aegis.plugins.path', base_path('plugins')), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .$name;

        if (is_dir($pluginPath)) {
            $this->error("Plugin directory [{$name}] already exists.");

            return CommandStatus::FAILURE;
        }

        File::makeDirectory($pluginPath.'/src', 0755, true);

        $namespace = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
        $provider = $namespace.'\\'.$namespace.'ServiceProvider';

        $manifest = [
            'name' => $name,
            'version' => '0.1.0',
            'description' => "Plugin {$name}",
            'author' => 'Aegis Team',
            'permissions' => ['none'],
            'provider' => $provider,
            'tools' => [],
            'autoload' => [
                'psr-4' => [
                    $namespace.'\\' => 'src/',
                ],
            ],
        ];

        File::put(
            $pluginPath.'/plugin.json',
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        File::put($pluginPath.'/src/'.$namespace.'ServiceProvider.php', $this->providerStub($namespace));

        $this->info("Created plugin scaffold at [plugins/{$name}].");

        return CommandStatus::SUCCESS;
    }

    private function providerStub(string $namespace): string
    {
        return "<?php\n\nnamespace {$namespace};\n\nuse App\\Plugins\\PluginServiceProvider;\n\nclass {$namespace}ServiceProvider extends PluginServiceProvider\n{\n    public function pluginName(): string\n    {\n        return '{$namespace}';\n    }\n}\n";
    }
}
