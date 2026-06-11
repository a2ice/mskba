<?php

namespace App\Modules\Admin\Application\UseCases;

final class ListAdminContentPagesHandler
{
    /**
     * @return array<int, array<string, string>>
     */
    public function handle(): array
    {
        return [
            [
                'path' => '/',
                'title' => 'Главная',
                'seo_title' => 'MSKBA - баскетбольная платформа Москвы',
                'keywords' => 'баскетбол, Москва, игры, тренировки',
                'description' => 'Публичная главная страница проекта MSKBA.',
                'status' => 'Каркас',
            ],
            [
                'path' => '/venues',
                'title' => 'Площадки',
                'seo_title' => 'Баскетбольные площадки Москвы',
                'keywords' => 'площадки, залы, баскетбол',
                'description' => 'Каталог баскетбольных площадок и залов.',
                'status' => 'Каркас',
            ],
            [
                'path' => '/faq',
                'title' => 'FAQ',
                'seo_title' => 'FAQ MSKBA',
                'keywords' => 'вопросы, помощь, MSKBA',
                'description' => 'Ответы на частые вопросы пользователей.',
                'status' => 'Каркас',
            ],
            [
                'path' => '/faq/welcome',
                'title' => 'Первые шаги',
                'seo_title' => 'Первые шаги в MSKBA',
                'keywords' => 'регистрация, старт, профиль',
                'description' => 'Первые действия пользователя после регистрации.',
                'status' => 'Каркас',
            ],
        ];
    }
}
