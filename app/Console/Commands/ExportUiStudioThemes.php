<?php

namespace App\Console\Commands;

use App\Presentation\Theming\DesignTheme;
use App\Presentation\Theming\DesignThemeCodeManager;
use App\Presentation\UiStudio\ThemeSnapshot;
use Illuminate\Console\Command;

final class ExportUiStudioThemes extends Command
{
    protected $signature = 'ui-studio:export-themes';
    protected $description = 'Export published visual theme assets only, as JSON on stdout';

    public function handle(ThemeSnapshot $snapshot, DesignThemeCodeManager $code): int
    {
        $themes = [];
        foreach (DesignTheme::query()->where('is_published', true)->orderBy('slug')->get() as $theme) {
            $themes[$theme->slug] = $snapshot->entry($theme, $code->activeSource($theme, 'js') ?? '');
        }
        if ($themes === []) {
            $this->error('No published themes.');

            return self::FAILURE;
        }
        $this->output->write(json_encode([
            'version' => 1, 'exported_at' => now()->toIso8601String(), 'themes' => $themes,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", false, \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
