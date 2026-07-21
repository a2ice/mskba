<?php

namespace App\Modules\Telegram\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Location\Application\UseCases\ListMetrostationsHandler;
use App\Modules\Telegram\Application\UseCases\AuthenticateTelegramMiniAppUserHandler;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class TelegramMiniAppController extends Controller
{
    public function main(ListMetrostationsHandler $listMetrostations): Response
    {
        return ThemeResolver::page('integrations.main', [
            'telegramBotUsername' => config('telegram.bot_username'),
            'venueTypes' => VenueTypeEnum::cases(),
            'venueStatuses' => VenueStatusEnum::cases(),
            'metros' => Schema::hasTable('metro_stations') ? $listMetrostations->handle() : [],
        ]);
    }

    public function home(): Response
    {
        return ThemeResolver::page('welcome', [
            'telegramMiniApp' => true,
        ]);
    }

    public function authenticate(Request $request, AuthenticateTelegramMiniAppUserHandler $authenticate): JsonResponse
    {
        $validated = $request->validate([
            'init_data' => ['required', 'string'],
        ]);

        try {
            $result = $authenticate->handle($validated['init_data']);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        }

        $user = $result['user'];
        $telegramAccount = $result['telegram_account'];

        return response()->json([
            'status' => 'success',
            'created' => $result['created'],
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'registration_channel' => $user->registration_channel?->value,
                'status' => $user->status?->value,
            ],
            'telegram_user' => [
                'id' => $telegramAccount->telegram_user_id,
                'username' => $telegramAccount->username,
                'first_name' => $telegramAccount->first_name,
                'last_name' => $telegramAccount->last_name,
                'photo_url' => $telegramAccount->photo_url,
                'start_param' => $telegramAccount->raw_data['start_param'] ?? null,
                'chat_type' => $telegramAccount->raw_data['chat_type'] ?? null,
                'chat_instance' => $telegramAccount->raw_data['chat_instance'] ?? null,
            ],
        ]);
    }
}
