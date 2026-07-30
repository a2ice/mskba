<?php

namespace App\Modules\Event\Application\Services;

final class GameStatisticsFields
{
    /** @return array<string, array{label: string, tooltip: string}> */
    public function all(): array
    {
        return [
            'minutes' => ['label' => 'Мин', 'tooltip' => 'Минуты, проведённые игроком на площадке.'],
            'close_made' => ['label' => 'Ближ. +', 'tooltip' => 'Попадания с ближней дистанции.'],
            'close_attempted' => ['label' => 'Ближ. всего', 'tooltip' => 'Все попытки бросков с ближней дистанции.'],
            'mid_made' => ['label' => 'Сред. +', 'tooltip' => 'Попадания со средней дистанции.'],
            'mid_attempted' => ['label' => 'Сред. всего', 'tooltip' => 'Все попытки бросков со средней дистанции.'],
            'three_made' => ['label' => '3PT +', 'tooltip' => 'Попадания трёхочковых бросков.'],
            'three_attempted' => ['label' => '3PT всего', 'tooltip' => 'Все попытки трёхочковых бросков.'],
            'free_throw_made' => ['label' => 'Штр. +', 'tooltip' => 'Попадания штрафных бросков.'],
            'free_throw_attempted' => ['label' => 'Штр. всего', 'tooltip' => 'Все попытки штрафных бросков.'],
            'offensive_rebounds' => ['label' => 'Подб. ат.', 'tooltip' => 'Подборы в нападении.'],
            'defensive_rebounds' => ['label' => 'Подб. защ.', 'tooltip' => 'Подборы в защите.'],
            'assists' => ['label' => 'Передачи', 'tooltip' => 'Результативные передачи.'],
            'steals' => ['label' => 'Перехваты', 'tooltip' => 'Перехваты мяча.'],
            'blocks' => ['label' => 'Блоки', 'tooltip' => 'Блок-шоты.'],
            'turnovers' => ['label' => 'Потери', 'tooltip' => 'Потери мяча.'],
            'fouls' => ['label' => 'Фолы', 'tooltip' => 'Персональные фолы игрока.'],
        ];
    }
}
