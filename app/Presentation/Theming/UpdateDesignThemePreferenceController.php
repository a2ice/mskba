<?php

namespace App\Presentation\Theming;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UpdateDesignThemePreferenceController
{
    public function __invoke(Request $request, DesignThemeManager $manager): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'design_theme_id' => [
                'nullable', 'integer',
                Rule::exists('design_themes', 'id')->where('is_published', true),
            ],
        ]);
        /** @var User $user */
        $user = $request->user()->canonical();
        $user->forceFill(['design_theme_id' => $validated['design_theme_id'] ?? null])->save();
        $theme = $manager->forUser($user);

        if ($request->expectsJson()) {
            return response()->json([
                'theme' => $theme->slug,
                'name' => $theme->name,
                'mode' => $theme->mode,
                'css_url' => $manager->cssUrl($theme),
                'preference_id' => $user->design_theme_id,
            ]);
        }

        return back()->with('status', 'Тема оформления сохранена.');
    }
}
