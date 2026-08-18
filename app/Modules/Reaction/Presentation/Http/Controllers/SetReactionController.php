<?php

namespace App\Modules\Reaction\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reaction\Application\Services\ReactionService;
use App\Modules\Reaction\Application\Services\ReactionSubjectGuard;
use App\Modules\Reaction\Domain\Enums\ReactionSubjectTypeEnum;
use App\Modules\Reaction\Domain\Enums\ReactionValueEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SetReactionController extends Controller
{
    public function __invoke(
        Request $request,
        string $subjectType,
        int $subjectId,
        ReactionSubjectGuard $subjects,
        ReactionService $reactions,
    ): JsonResponse {
        $type = ReactionSubjectTypeEnum::tryFrom($subjectType);
        abort_if($type === null, 404);

        $subjects->ensureReactable($type, $subjectId);

        $validated = $request->validate([
            'value' => ['nullable', 'integer', Rule::in([
                ReactionValueEnum::LIKE->value,
                ReactionValueEnum::DISLIKE->value,
            ])],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $value = isset($validated['value'])
            ? ReactionValueEnum::from((int) $validated['value'])
            : null;

        return response()->json(
            $reactions->setForUser($type, $subjectId, $user, $value)->toArray(),
        );
    }
}
