<?php

namespace App\Modules\Admin\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coordination\Domain\Events\PollChanged;
use App\Modules\Telegram\Application\Services\TelegramChatRegistry;
use App\Modules\Telegram\Domain\Models\TelegramChat;
use App\Presentation\Theming\ThemeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class AdminTelegramChatsController extends Controller
{
    public function index(TelegramChatRegistry $registry): Response
    {
        $registry->activeCoordinationChats();

        return ThemeResolver::page('admin.telegram-chats', [
            'chats' => TelegramChat::query()->orderBy('title')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'telegram_chat_id' => ['required', 'integer', Rule::unique('telegram_chats')],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:32'],
        ]);

        TelegramChat::query()->create([
            ...$data,
            'is_active' => true,
            'publishes_coordination' => true,
        ]);

        return back()->with('status', 'Telegram-чат подключён.');
    }

    public function update(Request $request, TelegramChat $telegramChat): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'publishes_coordination' => ['required', 'boolean'],
        ]);

        $telegramChat->update($data);
        $telegramChat->coordinationPublications()
            ->pluck('poll_id')
            ->unique()
            ->each(fn ($pollId) => event(new PollChanged((int) $pollId)));

        return back()->with('status', 'Настройки Telegram-чата сохранены.');
    }
}
