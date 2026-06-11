<?php

namespace App\Modules\Admin\Application\UseCases;

final class GetAdminSettingsHandler
{
    /**
     * @return array<int, array{title: string, description: string, fields: array<int, array{label: string, value: string, type: string}>}>
     */
    public function handle(): array
    {
        return [
            [
                'title' => 'Проект',
                'description' => 'Базовые параметры публичной части.',
                'fields' => [
                    ['label' => 'Название проекта', 'value' => config('app.name', 'MSKBA'), 'type' => 'text'],
                    ['label' => 'Публичный режим', 'value' => 'Включен', 'type' => 'toggle'],
                ],
            ],
            [
                'title' => 'Регистрация и модерация',
                'description' => 'Параметры пользовательских заявок и площадок.',
                'fields' => [
                    ['label' => 'Регистрация', 'value' => 'Включена', 'type' => 'toggle'],
                    ['label' => 'Модерация площадок', 'value' => 'Требуется подтверждение', 'type' => 'toggle'],
                ],
            ],
            [
                'title' => 'SEO defaults',
                'description' => 'Базовые SEO-значения до появления хранилища контента.',
                'fields' => [
                    ['label' => 'Default title', 'value' => 'MSKBA', 'type' => 'text'],
                    ['label' => 'Keywords', 'value' => 'баскетбол, Москва, MSKBA', 'type' => 'text'],
                    ['label' => 'Description', 'value' => 'Баскетбольная платформа Москвы.', 'type' => 'textarea'],
                ],
            ],
            [
                'title' => 'Система',
                'description' => 'Текущее окружение и состояние приложения.',
                'fields' => [
                    ['label' => 'Окружение', 'value' => app()->environment(), 'type' => 'text'],
                    ['label' => 'Режим настроек', 'value' => 'Read-only', 'type' => 'text'],
                ],
            ],
        ];
    }
}
