<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

#[Signature('make:module {name : Bounded context name} {--m|model= : Create the main Eloquent model. Pass a value to set model name} {--migration : Create a migration for the generated model} {--force : Overwrite generated files if they already exist}')]
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

        $modelName = $this->modelName($moduleName);

        if ($modelName !== null && ! preg_match('/^[A-Z][A-Za-z0-9]*$/', $modelName)) {
            $this->error('Model name must contain only letters, digits, dashes, underscores, or spaces.');

            return self::FAILURE;
        }

        if ($this->option('migration') && $modelName === null) {
            $this->error('The --migration option requires --model.');

            return self::FAILURE;
        }

        $tableName = $modelName === null ? null : $this->tableName($modelName);
        $migrationPath = $tableName === null ? null : $this->migrationPath($tableName);

        if ($this->option('migration') && $migrationPath !== null && File::exists($migrationPath) && ! $this->option('force')) {
            $this->error("Migration for table [{$tableName}] already exists. Use --force to overwrite it.");

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

            if ($directory !== 'Domain/Models' || $modelName === null) {
                $this->writeFile("{$path}/.gitkeep", '');
            }
        }

        $this->writeFile(
            "{$modulePath}/README.md",
            $this->readme($moduleName),
        );

        if ($modelName !== null) {
            $this->writeFile(
                "{$modulePath}/Domain/Models/{$modelName}.php",
                $this->model($moduleName, $modelName, $tableName),
            );
        }

        if ($this->option('migration') && $tableName !== null && $migrationPath !== null) {
            $this->writeFile(
                $migrationPath,
                $this->migration($tableName),
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

    private function modelName(string $moduleName): ?string
    {
        $hasModelOption = $this->input->hasParameterOption(['--model', '-m'], true);

        if (! $hasModelOption) {
            return null;
        }

        $model = $this->option('model');

        if ($model === null || $model === '' || $model === true || $model === '1' || $model === 'true') {
            return $moduleName;
        }

        return Str::studly(str_replace(['-', '_', '/', '\\'], ' ', (string) $model));
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

    private function tableName(string $modelName): string
    {
        return Str::snake(Str::pluralStudly($modelName));
    }

    private function migrationPath(string $tableName): string
    {
        $existingMigrations = File::glob(database_path("migrations/*_create_{$tableName}_table.php"));

        if ($existingMigrations !== []) {
            sort($existingMigrations);

            return (string) end($existingMigrations);
        }

        return database_path('migrations/'.date('Y_m_d_His')."_create_{$tableName}_table.php");
    }

    private function model(string $moduleName, string $modelName, string $tableName): string
    {
        $table = $tableName;

        return <<<PHP
<?php

namespace App\Modules\\{$moduleName}\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;

#[Fillable([])]
#[Hidden([])]
class {$modelName} extends Model
{
    // protected \$table = '{$table}';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }
}

PHP;
    }

    private function migration(string $tableName): string
    {
        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('{$tableName}', function (Blueprint \$table): void {
            \$table->id();
            \$table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
    }
};

PHP;
    }
}
