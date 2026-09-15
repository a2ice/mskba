<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Services\NicknameSuggestionService;
use App\Modules\Identity\Application\Services\PublicUserProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class UpdateAccountNicknameController
{
    public function __invoke(
        Request $request,
        PublicUserProfileService $profiles,
        NicknameSuggestionService $nicknames,
    ): JsonResponse {
        $user = $request->user()?->canonical();
        abort_unless($user, 401);

        $rawNickname = $request->input('nickname');
        $nickname = is_string($rawNickname) ? strtolower(trim($rawNickname)) : $rawNickname;
        if ($nickname === '') {
            $nickname = null;
        }

        $validator = Validator::make(
            ['nickname' => $nickname],
            ['nickname' => ['nullable', 'string', 'min:3', 'max:30', 'regex:/^[a-z][a-z0-9_]*$/']],
            [
                'nickname.string' => 'Никнейм должен быть строкой.',
                'nickname.min' => 'Никнейм должен содержать минимум 3 символа.',
                'nickname.max' => 'Никнейм должен содержать не более 30 символов.',
                'nickname.regex' => 'Никнейм должен начинаться с латинской буквы и содержать только латинские буквы, цифры и подчёркивания.',
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Проверьте никнейм.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($nickname !== null && ! $nicknames->isAvailableFor($user, $nickname)) {
            return response()->json([
                'message' => 'Этот никнейм уже занят.',
                'errors' => ['nickname' => ['Этот никнейм уже занят.']],
            ], 422);
        }

        $user->forceFill(['nickname' => $nickname])->save();

        return response()->json([
            'message' => $nickname === null ? 'Никнейм удалён.' : 'Никнейм сохранён.',
            'nickname' => $nickname,
            'public_url' => $profiles->url($user->fresh()),
        ]);
    }
}
