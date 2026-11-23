<?php

namespace App\Presentation\UiStudio;

use Illuminate\Support\Arr;

final class UiStudioReviewStore
{
    public function fingerprint(string $slug, array $case): string
    {
        $sources = $case['sources'] ?? ['resources/views/ui-studio/cases/'.$slug.'.blade.php'];
        $parts = [];
        foreach ($sources as $relative) {
            abort_unless(is_string($relative)
                && str_starts_with($relative, 'resources/')
                && ! str_contains($relative, '..')
                && is_file(base_path($relative)), 503, 'UI Studio source is missing');
            $parts[] = $relative."\0".hash_file('sha256', base_path($relative));
        }

        return hash('sha256', implode("\0", $parts));
    }

    public function accepted(string $slug, array $case): ?array
    {
        $path = $this->reviewPath($slug, (int) $case['revision']);
        if (! is_file($path)) return null;
        $review = json_decode((string) file_get_contents($path), true);
        if (! is_array($review) || ($review['fingerprint'] ?? null) !== $this->fingerprint($slug, $case)) {
            return null;
        }

        return $review;
    }

    /** @param list<string> $selected */
    public function accept(string $slug, array $case, array $selected, string $themeHash, int $userId): array
    {
        $changes = $case['kit_changes'] ?? [];
        $allowed = array_column($changes, 'id');
        abort_unless(count($selected) === count(array_unique($selected))
            && count(array_diff($selected, $allowed)) === 0, 422);

        $fingerprint = $this->fingerprint($slug, $case);
        $review = [
            'case' => $slug, 'revision' => (int) $case['revision'],
            'fingerprint' => $fingerprint, 'theme_hash' => $themeHash,
            'reviewer_id' => $userId, 'accepted_at' => now()->toIso8601String(),
            'kit_selected' => array_values($selected),
            'kit_declined' => array_values(array_diff($allowed, $selected)),
            'kit_changes' => $changes,
            'status' => 'accepted_for_integration',
        ];
        $path = $this->reviewPath($slug, (int) $case['revision']);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            abort(503, 'UI Studio review storage unavailable');
        }
        $tmp = tempnam($directory, '.review-');
        abort_unless($tmp !== false, 503);
        try {
            file_put_contents($tmp, json_encode($review, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
            chmod($tmp, 0600);
            abort_unless(rename($tmp, $path), 503);
        } finally {
            if (is_file($tmp)) unlink($tmp);
        }

        return $review;
    }

    private function reviewPath(string $slug, int $revision): string
    {
        abort_unless(preg_match('/\A[a-z][a-z0-9-]{1,79}\z/D', $slug) && $revision > 0, 422);

        return storage_path('app/private/ui-studio/reviews/'.$slug.'-r'.$revision.'.json');
    }
}
