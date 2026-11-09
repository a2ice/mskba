<?php

namespace App\Modules\Ai\Application\Services;

final class PlayerCharacterGenerationPromptBuilder
{
    /** @param array<string, mixed> $payload */
    public function build(array $payload): string
    {
        $appearance = (array) ($payload['appearance'] ?? []);
        $team = is_array($payload['team'] ?? null) ? $payload['team'] : null;
        $requestedUniform = is_array($payload['uniform'] ?? null) ? $payload['uniform'] : null;
        $uniformSource = (string) ($requestedUniform['source'] ?? (
            $team && ! empty($team['colors']) ? 'team' : 'neutral'
        ));
        $uniform = [
            'source' => $uniformSource,
            'name' => in_array($uniformSource, ['mine', 'team'], true) ? ($requestedUniform['name'] ?? null) : null,
            'primary_color' => $requestedUniform['primary_color'] ?? null,
            'secondary_color' => $requestedUniform['secondary_color'] ?? null,
            'sleeve_style' => $requestedUniform['sleeve_style'] ?? 'sleeveless',
            'logo_source' => $requestedUniform['logo_source'] ?? 'none',
            'number_enabled' => (bool) ($requestedUniform['number_enabled'] ?? true),
            'jersey_number_scale' => (float) ($requestedUniform['jersey_number_scale'] ?? 1),
            'shorts_number_scale' => (float) ($requestedUniform['shorts_number_scale'] ?? 1),
            'jersey_number_x' => (int) ($requestedUniform['jersey_number_x'] ?? 225),
            'jersey_number_y' => (int) ($requestedUniform['jersey_number_y'] ?? 394),
            'shorts_number_x' => (int) ($requestedUniform['shorts_number_x'] ?? 178),
            'shorts_number_y' => (int) ($requestedUniform['shorts_number_y'] ?? 755),
            'jersey_number' => in_array($uniformSource, ['mine', 'team'], true)
                && ($uniformSource === 'mine' || ($requestedUniform['jersey_number'] ?? null) !== null)
                ? str_pad((string) max($uniformSource === 'team' ? 0 : 1,
                    min(999, (int) ($requestedUniform['jersey_number'] ?? 1))), 2, '0', STR_PAD_LEFT) : null,
            'number_color' => $requestedUniform['number_color'] ?? null,
            'number_outline' => (bool) ($requestedUniform['number_outline'] ?? false),
            'number_outline_color' => $requestedUniform['number_outline_color'] ?? null,
            'logo_front_position' => $requestedUniform['logo_front_position'] ?? 'left',
            'logo_back_position' => $requestedUniform['logo_back_position'] ?? 'above',
        ];
        // Logo visibility is resolved by the selected uniform, not a profile-level toggle.
        $withUniformLogo = in_array($uniform['logo_source'], ['team', 'custom'], true)
            && ($uniformSource !== 'mine' || $uniform['logo_front_position'] !== 'none');
        $female = ($payload['gender'] ?? null) === 'female';
        $chestVolume = $female ? ($appearance['chest_volume'] ?? 'medium') : null;
        $chestDescription = $female ? match ($chestVolume) {
            'small' => 'small bust with a relatively flat upper-torso contour',
            'large' => 'fuller bust with a clearly rounded upper-torso contour',
            'full' => 'pronounced fuller bust, visibly more projected and fuller than medium or large, while keeping realistic athletic proportions',
            default => 'medium bust with a natural, moderately rounded upper-torso contour',
        } : null;

        // The image model sees opaque enum identifiers otherwise. Translate
        // only actually selected accessories into precise visible placements.
        $accessoryDescriptions = [
            'elbow_left' => 'A distinct athletic elbow pad on the PLAYER\'S LEFT elbow (viewer\'s right), centered on the elbow joint, not on the wrist.',
            'elbow_right' => 'A distinct athletic elbow pad on the PLAYER\'S RIGHT elbow (viewer\'s left), centered on the elbow joint, not on the wrist.',
            'wristband_left' => 'A fabric wrist sweatband on the PLAYER\'S LEFT wrist (viewer\'s right), below the forearm.',
            'wristband_right' => 'A fabric wrist sweatband on the PLAYER\'S RIGHT wrist (viewer\'s left), below the forearm.',
            'knee_left' => 'An athletic kneepad around the PLAYER\'S LEFT knee (viewer\'s right), not above the thigh.',
            'knee_right' => 'An athletic kneepad around the PLAYER\'S RIGHT knee (viewer\'s left), not above the thigh.',
            'headband' => 'MANDATORY: a clearly visible athletic fabric sweatband wrapped horizontally around the forehead at the hairline, immediately above the eyebrows. Show its front band against the forehead, in front of the hair, with sufficient contrast to be identifiable in the front view. It must be on the HEAD, never on an elbow, upper arm or wrist.',
        ];
        $selectedAccessories = [];
        foreach ((array) ($appearance['attributes'] ?? []) as $attribute) {
            if (is_string($attribute) && isset($accessoryDescriptions[$attribute])) {
                $selectedAccessories[$attribute] = '- '.$accessoryDescriptions[$attribute];
            }
        }
        $accessoryInstructions = $selectedAccessories
            ? implode("\n", array_values($selectedAccessories))
            : '- No sports accessories are selected.';

        $description = [
            'gender' => $payload['gender'] ?? null,
            'height_cm' => $payload['height_cm'] ?? null,
            'weight_kg' => $payload['weight_kg'] ?? null,
            'body_type' => $payload['body_type'] ?? null,
            // No hidden body-fat setting: physiology is described only through
            // the visible body type and the player's recorded height and weight.
            'body_type_description' => match ($payload['body_type'] ?? 'normal') {
                'slim' => 'slim build',
                'athletic' => 'athletic build',
                'muscular' => 'muscular build',
                'stocky' => 'stocky build',
                'large' => 'fuller build',
                default => 'normal build',
            },
            'shoes' => $appearance['shoes'] ?? 'white',
            'attributes' => array_values((array) ($appearance['attributes'] ?? [])),
            'chest_volume' => $chestVolume,
            'chest_volume_description' => $chestDescription,
            'uniform' => $uniform,
            'team' => $team ? [
                'name' => $team['name'] ?? null,
                'colors' => $team['colors'] ?? null,
                'with_logo' => $withUniformLogo,
            ] : null,
        ];

        $json = json_encode(
            $description,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );

        $uniformLogoInstruction = $withUniformLogo
            ? <<<'TEXT'
- The LAST supplied image is the selected logo reference. It is NOT a face or identity reference.
- Copy that supplied selected logo onto the jersey as faithfully and literally as possible in a natural basketball-uniform placement.
- Do NOT redesign, stylize, simplify, reinterpret, substitute, replace, or invent the selected logo.
- Preserve the logo's geometry, layout, colors, proportions, internal marks, and high-contrast details. If the logo is a QR code, preserve the QR module pattern and quiet-zone structure as accurately as possible.
- The jersey logo must visually match the LAST supplied image, not merely represent the team identity or theme.
TEXT
            : <<<'TEXT'
- Do not place a selected logo on the uniform.
TEXT;

        if ($withUniformLogo && in_array($uniformSource, ['mine', 'team'], true)) {
            $placement = ($uniform['logo_front_position'] ?? 'left') === 'right'
                ? "the PLAYER'S RIGHT upper chest (viewer's left)"
                : "the PLAYER'S LEFT upper chest (viewer's right)";
            $uniformLogoInstruction .= "\n- Place a SMALL logo only on {$placement}, matching the saved uniform design's logo placement. No central or oversized crest.";
        }

        $numberInstruction = in_array($uniformSource, ['mine', 'team'], true)
            && $uniform['number_enabled'] && $uniform['jersey_number'] !== null
            ? '- Render exactly the jersey number "'.$uniform['jersey_number'].'" on the front of the jersey and in a smaller size on the left side of the image (the right shorts leg of the player). '
                .'Use number_color if set, otherwise the secondary_color when present, or high contrast (white on dark, black on light) when absent. '
                .'When number_outline is true, add a visible outline in number_outline_color if supplied. '
                .'Otherwise, outline in the primary color if secondary_color exists; without secondary_color use black lettering with a white outline on dark fabric, '
                .'or white lettering with a black outline on light fabric. Respect the saved front jersey and shorts scale and placement fields (these are SVG pattern-space units, not pixel coordinates of the player image), keeping both numbers within their respective garments. Never change the number or add any other lettering.'
            : '- No number is enabled for the selected uniform. The jersey AND shorts must have no numerals anywhere. Do not invent any lettering or numerals.';

        return <<<PROMPT
Create ONE photorealistic full-body basketball player using all supplied face-reference images as identity references.

Reference mapping:
- front is the frontal face reference;
- left is the subject's left facial profile;
- right is the subject's right facial profile.
{$uniformLogoInstruction}

Identity:
- Preserve the same person's facial identity from all face-reference images as closely as possible.
- Use face-reference images for identity only; do not copy their background, crop, lighting, clothing, or camera angle.

Composition - STRICT:
- One person only.
- Full body from head to both feet, completely inside frame with comfortable transparent padding.
- Front view, facing the camera directly.
- Standing upright in a neutral pose / neutral athletic stance.
- Arms relaxed and slightly away from the torso; hands empty and clearly visible.
- NO basketball. Do not put a basketball in either hand, under an arm, at the feet, or anywhere in the image.
- NO props of any kind.
- NO extra accessories unless explicitly selected in Character parameters.
- Camera at approximately waist/chest height, natural perspective, no dramatic foreshortening.
- Realistic anatomy and proportions appropriate for the requested height, weight, body type, and gender.
- Basketball-player physique, not a bodybuilder caricature.
- Respect the selected body_type and requested body proportions without exaggeration. Do not infer diagnoses or stigmatize body size.
- Use height and weight for realistic body proportions; do not infer or reuse any hidden visual body-fat control.
- For a female player, chest_volume_description is an explicit body-proportion requirement, not an optional styling hint. Make small, medium, large and full visibly distinguishable in the front-view upper-torso silhouette while keeping the player fully clothed, natural and athletic.
- A full chest must have visibly more volume and jersey contour than medium, even when wearing a loose basketball jersey. Let the uniform drape naturally over the specified body shape; do not flatten or ignore the selected chest size. Preserve the same face, height, weight and overall body type when only chest_volume changes.

Clothing - SELECTED UNIFORM IS AUTHORITATIVE:
- Modern basketball jersey and matching shorts. Respect the supplied uniform.source and sleeve_style.
- uniform.source = "neutral": use a neutral light slate/gray jersey and matching shorts. Do not reuse old team colors or invent branding beyond the explicitly supplied uniform logo.
- uniform.source = "team": use the selected team's configured primary_color as the main jersey AND shorts color and secondary_color as trim. Do not invent additional team markings.
- uniform.source = "mine": reproduce the saved design's specified primary_color on jersey and shorts, and use secondary_color for borders, piping and trim ONLY when present. When absent, make a monochrome uniform. The saved design name is descriptive metadata only; do not print it on clothing.
- sleeve_style = "sleeved": use a basketball jersey with short athletic sleeves, visibly covering the upper arms. sleeve_style = "sleeveless": use a sleeveless tank jersey.
- For every source, keep shirt and shorts coordinated. A source with no configured team kit has already been normalized to neutral by the server; never guess a palette from the team name.
{$uniformLogoInstruction}
{$numberInstruction}
- Do not invent brand logos, sponsors, extra text, names, watermarks, or badges.
- Apply the requested shoes and the following SELECTED accessories exactly at their specified locations. Selected accessories are mandatory clothing items, not optional decorative suggestions. Do not confuse wrist, elbow, knee or head placement.
{$accessoryInstructions}
- Never add unselected accessories or silently omit selected ones.

OUTPUT REQUIREMENTS - STRICT:
- Background MUST be fully transparent alpha, not white, black, gray, checkerboard, studio, floor, gradient, or scenery.
- No floor plane and no cast shadow outside the player's silhouette.
- PNG with transparency.
- The player must be isolated and ready to place directly over the MSKBA height scale.

Character parameters:
{$json}
PROMPT;
    }
}
