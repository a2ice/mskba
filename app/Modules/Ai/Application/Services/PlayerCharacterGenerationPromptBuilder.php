<?php

namespace App\Modules\Ai\Application\Services;

final class PlayerCharacterGenerationPromptBuilder
{
    /** @param array<string, mixed> $payload */
    public function build(array $payload): string
    {
        $appearance = (array) ($payload['appearance'] ?? []);
        $team = is_array($payload['team'] ?? null) ? $payload['team'] : null;
        $withTeamLogo = (bool) ($team['with_logo'] ?? false);

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
            'chest_volume' => $appearance['chest_volume'] ?? null,
            'team' => $team ? [
                'name' => $team['name'] ?? null,
                'colors' => $team['colors'] ?? null,
                'with_logo' => $withTeamLogo,
            ] : null,
        ];

        $json = json_encode(
            $description,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );

        $teamLogoInstruction = $withTeamLogo
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

        return <<<PROMPT
Create ONE photorealistic full-body basketball player using all supplied face-reference images as identity references.

Reference mapping:
- front is the frontal face reference;
- left is the subject's left facial profile;
- right is the subject's right facial profile.
{$teamLogoInstruction}

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

Clothing:
- Modern basketball jersey and matching shorts.
- If team colors are supplied, use those colors as the uniform palette.
- If no team colors are supplied, use a neutral light slate/gray jersey and matching shorts; do not invent team colors or branding.
{$teamLogoInstruction}- Do not invent brand logos, sponsors, text, names, numbers, watermarks, or badges.
- Apply requested shoes and ONLY the explicitly requested sports attributes.

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
