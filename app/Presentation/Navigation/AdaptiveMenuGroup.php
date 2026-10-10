<?php

namespace App\Presentation\Navigation;

/**
 * Navigation grouping is a presentation choice made after all visibility and
 * deduplication rules have resolved the links a user actually receives.
 */
final class AdaptiveMenuGroup
{
    /**
     * @param list<array<string, mixed>> $links
     * @return list<array<string, mixed>>
     */
    public static function wrap(string $title, array $links): array
    {
        $links = array_values(array_filter(
            $links,
            static fn (array $item): bool => ($item['visible'] ?? true) === true,
        ));

        if (count($links) === 0) {
            return [];
        }

        if (count($links) === 1) {
            // Keep the lone link's URL, active state and badge untouched.
            // The mobile account accordion must still open on its active page.
            return [[...$links[0], 'openMobileOnActive' => true]];
        }

        return [[
            'label' => $title,
            'url' => null,
            'active' => collect($links)->contains(static fn (array $item): bool => ($item['active'] ?? false) === true),
            'visible' => true,
            'children' => $links,
        ]];
    }
}
