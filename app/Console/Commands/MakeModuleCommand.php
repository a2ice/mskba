<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

#[Signature('make:module {name : Bounded context name} {--m|model : Create the main Eloquent model} {--force : Overwrite generated files if they already exist}')]
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

        $ifCreateModel = $this->option('model');

        if ($moduleName === '' || ! preg_match('/^[A-Z][A-Za-z0-9]*$/', $moduleName)) {
            $this->error('Module name must contain only letters, digits, dashes, underscores, or spaces.');

            return self::FAILURE;
        }

        $modulePath = app_path("Modules/{$moduleName}");
        $moduleExists = File::exists($modulePath);

        if ($moduleExists && ! $this->option('force')) {
            $this->error("Module [{$moduleName}] already exists. Use --force to add missing files.");

            return self::FAILURE;
        }

        foreach ($this->directories() as $directory) {
            $path = "{$modulePath}/{$directory}";

            File::ensureDirectoryExists($path);
            if($path !== "{$modulePath}/Domain/Models" && !$ifCreateModel) {
                $this->writeFile("{$path}/.gitkeep", '');
            }
        }

        $this->writeFile(
            "{$modulePath}/README.md",
            $this->readme($moduleName),
        );

        if ($ifCreateModel) {
            $this->writeFile(
                "{$modulePath}/Domain/Models/{$moduleName}.php",
                $this->model($moduleName),
            );
        }

        $action = $moduleExists ? 'updated' : 'created';

        $this->info("Module [{$moduleName}] {$action} at app/Modules/{$moduleName}.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function directories(): array
    {
        return [
            'Application/DTO',
            'Application/Services',
            'Application/UseCases',
            'Domain/Enums',
            'Domain/Events',
            'Domain/Exceptions',
            'Domain/Models',
            'Domain/ValueObjects',
            'Infrastructure/ACL',
            'Infrastructure/Providers',
            'Presentation/Http/Controllers',
            'Presentation/Http/Requests',
            'Presentation/Http/Resources',
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

- `Domain`: business models, enums, events, exceptions, value objects.
- `Application`: use cases, DTOs, application services.
- `Infrastructure`: ACL, integrations, service providers.
- `Presentation`: HTTP controllers, requests, resources.
- `Tests`: module-level feature and unit tests.

MARKDOWN;
    }

    private function model(string $moduleName): string
    {
        $table = Str::snake(Str::pluralStudly($moduleName));

        return <<<PHP
<?php

namespace App\Modules\\{$moduleName}\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class {$moduleName} extends Model
{
    protected \$table = '{$table}';

    protected \$guarded = [];
}

PHP;
    }
}
