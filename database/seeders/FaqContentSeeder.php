<?php

namespace Database\Seeders;

use App\Modules\Content\Application\Services\ContentTagManager;
use App\Modules\Content\Domain\Enums\ContentFormatEnum;
use App\Modules\Content\Domain\Enums\ContentTypeEnum;
use App\Modules\Content\Domain\Models\ContentItem;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Seeder;

final class FaqContentSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::query()
            ->where('system_role', UserSystemRoleEnum::SUPERADMIN)
            ->orderBy('id')
            ->first()
            ?? User::query()->orderBy('id')->first();

        if (! $owner) {
            return;
        }

        $tags = app(ContentTagManager::class);

        foreach ($this->creationArticles() as $topic => $data) {
            $guide = config('creation-guides.'.$topic);
            if (! is_array($guide)) {
                continue;
            }

            $content = ContentItem::query()->firstOrCreate(
                ['system_key' => 'faq.creation.'.$topic],
                [
                    'created_by_user_id' => $owner->id,
                    'updated_by_user_id' => $owner->id,
                    'type' => ContentTypeEnum::FAQ,
                    'title' => $guide['heading'],
                    'alias' => 'faq-creation-'.$topic,
                    'short_description' => $guide['intro'],
                    'full_description' => $this->creationBody($topic, $guide['steps'] ?? []),
                    'content_format' => ContentFormatEnum::SAFE_HTML,
                    'publish_in_feed' => false,
                    'publish_in_telegram' => false,
                ],
            );

            if ($content->wasRecentlyCreated) {
                $tags->sync($content, implode(', ', $data['tags']));
            }
        }

        $welcome = ContentItem::query()->firstOrCreate(
            ['system_key' => 'faq.welcome'],
            [
                'created_by_user_id' => $owner->id,
                'updated_by_user_id' => $owner->id,
                'type' => ContentTypeEnum::FAQ,
                'title' => 'Первые шаги',
                'alias' => 'faq-welcome',
                'short_description' => 'Короткий маршрут после создания аккаунта и пояснения к условиям доступа.',
                'full_description' => $this->welcomeBody(),
                'content_format' => ContentFormatEnum::SAFE_HTML,
                'publish_in_feed' => false,
                'publish_in_telegram' => false,
            ],
        );

        if ($welcome->wasRecentlyCreated) {
            $tags->sync($welcome, 'аккаунт, подтверждение аккаунта, контакт, подтверждение контакта, роль, тренер, права доступа, создание');
        }
    }

    /** @return array<string, array{tags: list<string>}> */
    private function creationArticles(): array
    {
        return [
            'venues' => ['tags' => ['площадка', 'добавление площадки', 'модерация площадки', 'адрес площадки']],
            'events' => ['tags' => ['мероприятие', 'создание мероприятия', 'игра', 'тренировка', 'площадка мероприятия']],
            'teams' => ['tags' => ['команда', 'создание команды', 'капитан', 'участники команды']],
            'tournaments' => ['tags' => ['турнир', 'создание турнира', 'участники турнира', 'команды турнира']],
            'coordination' => ['tags' => ['опрос', 'создание опроса', 'голосование', 'согласование']],
            'sections' => ['tags' => ['секция', 'создание секции', 'тренер', 'занятия', 'тренировка']],
        ];
    }

    /** @param array<int, string> $steps */
    private function creationBody(string $topic, array $steps): string
    {
        $items = collect($steps)
            ->map(fn (string $step): string => '<li>'.e($step).'</li>')
            ->implode('');

        return '[creation_requirements topic="'.$topic.'"]<h2>Как это работает</h2><ol>'.$items.'</ol>';
    }

    private function welcomeBody(): string
    {
        return <<<'HTML'
[anchor id="contact-confirmation"]
<h2>1. Как подтвердить контакт</h2>
<p>Откройте раздел контактов, добавьте нужный способ связи и выполните доступное для него действие подтверждения. Для права создавать мероприятия и турниры достаточно хотя бы одного подтверждённого контакта, если администратор не установил персональный запрет. Для полного подтверждения аккаунта нужен подтверждённый основной контакт.</p>
<p><a href="/account/contacts">Перейти к контактам</a></p>

[anchor id="participation-role"]
<h2>2. Как выбрать роль участия</h2>
<p>В личном кабинете выберите актуальную роль участия в проекте. Для создания секции нужна активная роль тренера. Для ролей игрока и тренера при подтверждении аккаунта дополнительно требуются дата рождения и пол.</p>
<p><a href="/account/roles">Перейти к ролям</a></p>

[anchor id="account-confirmation"]
<h2>3. Как подтвердить аккаунт</h2>
<p>Откройте мастер подтверждения аккаунта. Обязательны подтверждённый основной контакт и выбранная роль участия. Для игрока и тренера также обязательны дата рождения и пол. Имя и фамилию можно заполнить дополнительно, но они не являются обязательным условием подтверждения.</p>
<p><a href="/account/confirmation">Подтвердить аккаунт</a></p>

[anchor id="creation-permissions"]
<h2>4. Как работают права на создание</h2>
<p>Права на создание отдельных сущностей проверяются отдельно от статуса аккаунта. Создание команд и опросов для активного пользователя разрешено по умолчанию, но администратор может персонально отключить это право. Создание мероприятий и турниров по умолчанию закрыто и для обычного аккаунта открывается после подтверждения контакта. Явный персональный запрет администратора имеет приоритет и повторное подтверждение контакта его не снимает.</p>
<p>Заблокированный или удалённый аккаунт не может создавать сущности проекта. Если страница создания сообщает об административном запрете, обратитесь к администратору проекта.</p>

[anchor id="notifications"]
<h2>5. Следите за уведомлениями</h2>
<p>После установки роли в центре уведомлений могут появиться системные сообщения и подсказки по следующим действиям.</p>
<p><a href="/account/notifications">Открыть уведомления</a></p>
HTML;
    }
}
