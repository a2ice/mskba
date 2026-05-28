<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

#[Signature('delete:module {name : Bounded context name} {--force : Delete without confirmation}')]
#[Description('Delete a bounded context module from app/Modules')]
class DeleteModuleCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $moduleName = $this->moduleName((string) $this->argument('name'));

        if ($moduleName === null) {
            $this->error('Module name must contain only letters, digits, dashes, underscores, or spaces.');

            return self::FAILURE;
        }

        $modulePath = app_path("Modules/{$moduleName}");

        if (! File::isDirectory($modulePath)) {
            $this->error("Module [{$moduleName}] does not exist.");

            return self::FAILURE;
        }

        $migrations = $this->relatedMigrations($modulePath);

        $this->warn('The following module will be deleted:');
        $this->line("  app/Modules/{$moduleName}");

        if ($migrations !== []) {
            $this->warn('The following related migrations will also be deleted:');

            foreach ($migrations as $migration) {
                $this->line('  '.$this->relativePath($migration));
            }
        }

        if (! $this->option('force') && ! $this->confirm('Continue?')) {
            $this->info('Deletion cancelled.');

            return self::SUCCESS;
        }

        foreach ($migrations as $migration) {
            File::delete($migration);
        }

        File::deleteDirectory($modulePath);

        $this->info("Module [{$moduleName}] deleted.");

        return self::SUCCESS;
    }

    private function moduleName(string $rawName): ?string
    {
        $moduleName = Str::studly(str_replace(['-', '_', '/', '\\'], ' ', $rawName));

        if ($moduleName === '' || ! preg_match('/^[A-Z][A-Za-z0-9]*$/', $moduleName)) {
            return null;
        }

        return $moduleName;
    }

    /**
     * @return array<int, string>
     */
    private function relatedMigrations(string $modulePath): array
    {
        $migrations = [];

        foreach ($this->modelNames($modulePath) as $modelName) {
            $tableName = Str::snake(Str::pluralStudly($modelName));

            foreach (File::glob(database_path("migrations/*_create_{$tableName}_table.php")) as $migration) {
                $migrations[] = $migration;
            }
        }

        $migrations = array_values(array_unique($migrations));
        sort($migrations);

        return $migrations;
    }

    /**
     * @return array<int, string>
     */
    private function modelNames(string $modulePath): array
    {
        $modelPath = "{$modulePath}/Domain/Models";

        if (! File::isDirectory($modelPath)) {
            return [];
        }

        return collect(File::files($modelPath))
            ->filter(fn ($file): bool => $file->getExtension() === 'php')
            ->map(fn ($file): string => $file->getBasename('.php'))
            ->values()
            ->all();
    }

    private function relativePath(string $path): string
    {
        return Str::after($path, base_path().DIRECTORY_SEPARATOR);
    }
}
