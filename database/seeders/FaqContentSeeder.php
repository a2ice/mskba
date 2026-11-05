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

            $content = ContentItem::withTrashed()->firstOrCreate(
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

        $welcome = ContentItem::withTrashed()->firstOrCreate(
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

        // Idempotent: the editor owns existing article text and publication state.
        foreach ($this->uniformArticles() as $key => $data) {
            $article = ContentItem::withTrashed()->firstOrCreate(
                ['system_key' => 'faq.uniforms.'.$key],
                [
                    'created_by_user_id' => $owner->id,
                    'updated_by_user_id' => $owner->id,
                    'type' => ContentTypeEnum::FAQ,
                    'title' => $data['title'],
                    'alias' => 'faq-uniform-'.$key,
                    'short_description' => $data['intro'],
                    'full_description' => $data['body'],
                    'content_format' => ContentFormatEnum::SAFE_HTML,
                    'publish_in_feed' => false,
                    'publish_in_telegram' => false,
                ],
            );
            if ($article->wasRecentlyCreated) {
                $tags->sync($article, $data['tags']);
            }
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

    /** @return array<string, array{title: string, intro: string, body: string, tags: string}> */
    private function uniformArticles(): array
    {
        return [
            'selection' => [
                'title' => 'Как подобрать готовую баскетбольную форму',
                'intro' => 'Что появится в каталоге экипировки MSKBA и как будут представлены предложения продавцов.',
                'body' => <<<'HTML'
<h2>Готовая экипировка для игроков и команд</h2>
<p>MSKBA готовит направление подбора баскетбольной формы: комплектов для тренировок, игр и турниров. В будущем можно будет сравнивать предложения по размерам, цвету, брендам, наличию и другим параметрам.</p>
<h2>Где купить форму сейчас?</h2>
<p>Каталог продавцов на портале пока не запущен, а MSKBA на этом этапе не принимает оплату за одежду. Изучать ассортимент можно непосредственно на площадках продавцов, в том числе на маркетплейсах. Упоминание Ozon, Wildberries и Яндекс Маркета не означает официального партнёрства с ними.</p>
<h2>Хотите предложить ассортимент?</h2>
<p>Мы открыты к диалогу с магазинами, производителями и продавцами. <a href="/faq/faq-uniform-partnership">Условия сотрудничества</a>.</p>
HTML,
                'tags' => 'форма, экипировка, подбор формы, каталог формы, баскетбольная одежда',
            ],
            'workshop' => [
                'title' => 'Как работает Мастерская формы MSKBA',
                'intro' => 'Демонстрационный конструктор игровой формы и план его развития.',
                'body' => <<<'HTML'
<h2>От идеи к своему стилю</h2>
<p>Мастерская MSKBA — это будущий конструктор баскетбольной экипировки для игроков, команд и клубов. Сейчас можно примерить палитру, указать игровой номер и надпись на спине, выбрать ориентировочный размер и скопировать параметры эскиза.</p>
<ol><li>Выберите цветовую схему и посмотрите макет.</li><li>Добавьте игровой номер и надпись на спине.</li><li>Выберите размер для предварительной спецификации.</li><li>Скопируйте параметры, чтобы сохранить идею.</li></ol>
<p>Это интерактивная демонстрация, а не готовое производственное лекало. Сохранение проектов, логотипы, ткани, согласование размера всей команды и отправка заказа производителю будут добавляться позднее.</p>
<p><a href="/uniforms/workshop">Открыть мастерскую на отдельной странице</a>.</p>
HTML,
                'tags' => 'форма, мастерская, конструктор формы, дизайн, эскиз, номера игроков',
            ],
            'production' => [
                'title' => 'Как заказать изготовление индивидуальной формы',
                'intro' => 'Планируемая схема изготовления формы по дизайну команды и важные ограничения демонстрации.',
                'body' => <<<'HTML'
<h2>Путь от дизайна до готовой экипировки</h2>
<p>Мы планируем, что пользователь сможет собрать дизайн в мастерской, выбрать комплект и размеры для игроков команды, запросить расчёт у производителя и согласовать заказ. После подтверждения появятся этапы изготовления и доставки.</p>
<ol><li>Создание и сохранение дизайна.</li><li>Формирование комплекта с размерами и количеством изделий.</li><li>Запрос стоимости и сроков у выбранного производителя.</li><li>Согласование условий и дальнейшая работа с заказом.</li></ol>
<p><strong>Сейчас эта цепочка ещё не запущена.</strong> Демонстрационная мастерская не отправляет заказы, не резервирует товары и не принимает оплату. Для обсуждения будущего производства можно написать на <a href="mailto:support@mskba.ru">support@mskba.ru</a>.</p>
HTML,
                'tags' => 'форма, производство, изготовление формы, пошив, заказ формы, экипировка',
            ],
            'partnership' => [
                'title' => 'Как стать партнёром MSKBA по экипировке',
                'intro' => 'Возможности для производителей, магазинов и продавцов баскетбольной формы.',
                'body' => <<<'HTML'
<h2>Производителям, магазинам и продавцам</h2>
<p>MSKBA развивает направление баскетбольной экипировки. Рассматриваем сотрудничество с производителями индивидуальной формы, магазинами спортивной одежды и продавцами маркетплейсов — в том числе теми, кто работает на Ozon, Wildberries и Яндекс Маркете. Эти названия приведены как примеры каналов продаж, а не как список партнёров портала.</p>
<h2>Что планируется</h2>
<ul><li>Каталог предложений с удобным подбором для игроков и команд.</li><li>Передача согласованных эскизов мастерской на расчёт и изготовление.</li><li>Профиль поставщика, описание ассортимента и условия сотрудничества.</li></ul>
<h2>Как предложить сотрудничество</h2>
<p>Напишите на <a href="mailto:support@mskba.ru?subject=MSKBA%20-%20%D1%81%D0%BE%D1%82%D1%80%D1%83%D0%B4%D0%BD%D0%B8%D1%87%D0%B5%D1%81%D1%82%D0%B2%D0%BE">support@mskba.ru</a>. Укажите название компании, сайт или витрину, специализацию, доступные размеры и условия производства или поставки. Принципы проверки продавцов, условия размещения и коммерческая модель будут согласовываться отдельно — официального подтверждения партнёрства до такого согласования нет.</p>
HTML,
                'tags' => 'сотрудничество, партнёр, производитель, поставщик, маркетплейс, экипировка, форма',
            ],
        ];
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
<p>В личном кабинете выберите актуальную роль участия в проекте. Для создания секции нужна активная роль тренера. Подтверждение аккаунта проходит отдельно от выбора роли и требует заполненных имени, фамилии, даты рождения, пола и подтверждённого основного контакта.</p>
<p><a href="/account/roles">Перейти к ролям</a></p>

[anchor id="account-confirmation"]
<h2>3. Как подтвердить аккаунт</h2>
<p>Откройте страницу подтверждения аккаунта в личном кабинете. Условия показаны отдельными карточками: подтверждённый основной контакт (Email, Telegram или другой поддерживаемый канал), имя, фамилия, дата рождения и пол. Выбор роли не обязателен. После подтверждения изменение этих данных оформляется отдельной заявкой с причиной и рассмотрением администратором.</p>
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
