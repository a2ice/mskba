@php
    $socialEvent = $socialEvent ?? null;
    $socialChats = app(\App\Modules\Telegram\Application\Services\TelegramChatRegistry::class)->activeEventChats();
    $existingSocialChats = $socialEvent?->telegramPublications()->pluck('chat_id')->map(fn ($id) => (string) $id)->all() ?? [];
    $availableSocialChats = $socialChats->reject(fn ($chat) => in_array((string) $chat->telegram_chat_id, $existingSocialChats, true));
    $chosenSocialChats = array_map('strval', (array) old('telegram_chat_ids', []));
@endphp
@if($socialChats->isNotEmpty())
    <details class="event-social-publication mb-3" @if(old('publish_to_telegram') || $existingSocialChats !== []) open @endif>
        <summary>Публикация в соцсетях</summary>
        <div class="mt-2">
            <details @if(old('publish_to_telegram') || $existingSocialChats !== []) open @endif>
                <summary>Telegram</summary>
                @foreach($socialChats as $socialChat)
                    @if(in_array((string) $socialChat->telegram_chat_id, $existingSocialChats, true))
                        <div class="check-label"><input type="checkbox" checked disabled><span>{{ $socialChat->title }} — уже выбрано</span></div>
                    @endif
                @endforeach
                @if($availableSocialChats->isNotEmpty())
                    <input type="hidden" name="publish_to_telegram" value="0">
                    <label class="check-label quick-event-dialog__game-toggle">
                        <input type="checkbox" name="publish_to_telegram" value="1" @checked(old('publish_to_telegram', false))>
                        <span>{{ $socialEvent ? 'Опубликовать в дополнительных группах' : 'Опубликовать в Telegram' }}</span>
                    </label>
                    @foreach($availableSocialChats as $socialChat)
                        <label class="check-label quick-event-dialog__game-toggle">
                            <input type="checkbox" name="telegram_chat_ids[]" value="{{ $socialChat->id }}" @checked(in_array((string) $socialChat->id, $chosenSocialChats, true))>
                            <span>{{ $socialChat->title ?: 'Чат '.$socialChat->telegram_chat_id }}</span>
                        </label>
                    @endforeach
                @endif
                <small>Публикуются открытые мероприятия с установленной датой и временем. При изменениях существующие посты обновляются.</small>
                @error('telegram_chat_ids') <p class="text-danger">{{ $message }}</p> @enderror
                @error('telegram_chat_ids.*') <p class="text-danger">{{ $message }}</p> @enderror
            </details>
        </div>
    </details>
@endif
