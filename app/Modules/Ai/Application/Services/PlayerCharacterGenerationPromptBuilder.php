<?php

namespace App\Modules\Ai\Application\Services;

final class PlayerCharacterGenerationPromptBuilder
{
    /** @param array<string, mixed> $payload */
    public function build(array $payload): string
    {
        $appearance = (array) ($payload['appearance'] ?? []);
        $team = is_array($payload['team'] ?? null) ? $payload['team'] : null;

        $description = [
            'gender' => $payload['gender'] ?? null,
            'height_cm' => $payload['height_cm'] ?? null,
            'weight_kg' => $payload['weight_kg'] ?? null,
            'body_type' => $payload['body_type'] ?? null,
            'shoes' => $appearance['shoes'] ?? 'white',
            'attributes' => array_values((array) ($appearance['attributes'] ?? [])),
            'chest_volume' => $appearance['chest_volume'] ?? null,
            'team' => $team ? [
                'name' => $team['name'] ?? null,
                'colors' => $team['colors'] ?? null,
            ] : null,
        ];

        $json = json_encode(
            $description,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );

        return <<<PROMPT
Create ONE photorealistic full-body basketball player using all supplied face-reference images as identity references.

Reference mapping:
- front is the frontal face reference;
- left is the subject's left facial profile;
- right is the subject's right facial profile.

Identity:
- Preserve the same person's facial identity from all reference images as closely as possible.
- Use the reference images for identity only; do not copy their background, crop, lighting, clothing, or camera angle.

Composition:
- One person only.
- Full body from head to both feet, completely inside frame with comfortable transparent padding.
- Standing upright, front-facing, neutral athletic stance, arms relaxed and slightly away from the torso.
- Camera at approximately waist/chest height, natural perspective, no dramatic foreshortening.
- Realistic anatomy and proportions appropriate for the requested height, weight, body type, and gender.
- Basketball-player physique, not a bodybuilder caricature.

Clothing:
- Modern basketball jersey and matching shorts.
- If team colors are supplied, use those colors as the uniform palette.
- Do not invent brand logos, sponsors, text, names, numbers, watermarks, or badges.
- Apply requested shoes and sports attributes naturally.

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
