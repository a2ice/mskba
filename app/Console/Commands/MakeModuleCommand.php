<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

#[Signature('make:module {name : Bounded context name} {--force : Overwrite generated files if they already exist}')]
#[Description('Create a bounded context module structure in app/Modules')]
class MakeModuleCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawName = (string) $this->argument('name');
        $moduleName = Str::studly(str_replace(['-', '_', '/', '\\'], ' ', $rawName));

        if ($moduleName === '' || ! preg_match('/^[A-Z][A-Za-z0-9]*$/', $moduleName)) {
            $this->error('Module name must contain only letters, digits, dashes, underscores, or spaces.');

            return self::FAILURE;
        }

        $modulePath = app_path("Modules/{$moduleName}");

        if (File::exists($modulePath) && ! $this->option('force')) {
            $this->error("Module [{$moduleName}] already exists. Use --force to add missing files.");

            return self::FAILURE;
        }

        foreach ($this->directories() as $directory) {
            $path = "{$modulePath}/{$directory}";

            File::ensureDirectoryExists($path);
            $this->writeFile("{$path}/.gitkeep", '');
        }

        $this->writeFile(
            "{$modulePath}/README.md",
            $this->readme($moduleName),
        );

        $this->info("Module [{$moduleName}] created at app/Modules/{$moduleName}.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function directories(): array
    {
        return [
            'Application/Commands',
            'Application/DTO',
            'Application/Queries',
            'Application/Services',
            'Domain/Entities',
            'Domain/Enums',
            'Domain/Events',
            'Domain/Exceptions',
            'Domain/Repositories',
            'Domain/ValueObjects',
            'Infrastructure/Persistence',
            'Infrastructure/Providers',
            'Presentation/Http/Controllers',
            'Presentation/Http/Requests',
            'Presentation/Http/Resources',
            'Presentation/routes',
            'Tests/Feature',
            'Tests/Unit',
        ];
    }

    private function writeFile(string $path, string $contents): void
    {
        if (File::exists($path) && ! $this->option('force')) {
            return;
        }

        File::put($path, $contents);
    }

    private function readme(string $moduleName): string
    {
        return <<<MARKDOWN
# {$moduleName}

Bounded context module.

## Structure

- `Domain`: business rules, entities, value objects, repository contracts.
- `Application`: use cases, commands, queries, DTOs, application services.
- `Infrastructure`: persistence, integrations, service providers.
- `Presentation`: HTTP controllers, requests, resources, routes.
- `Tests`: module-level feature and unit tests.

MARKDOWN;
    }
}
