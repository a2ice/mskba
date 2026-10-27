<div
    wire:ignore
    x-data="venuePhotoCropEditor({{ (int) $photoId }}, @js($selection))"
    class="venue-photo-crop"
>
    <p class="venue-photo-crop__hint">
        Перемещайте рамку или тяните за её стороны и углы.
        На телефоне можно также изменять размер области двумя пальцами.
    </p>

    <div class="venue-photo-crop__viewport">
        <div
            x-ref="stage"
            class="venue-photo-crop__stage"
            x-on:pointerdown="start($event)"
            x-on:pointermove="move($event)"
            x-on:pointerup="end($event)"
            x-on:pointercancel="end($event)"
        >
            <img src="{{ $sourceUrl }}" alt="Исходная фотография площадки" draggable="false">

            <div class="venue-photo-crop__frame" :style="frameStyle" data-crop-frame>
                <span class="venue-photo-crop__thirds" aria-hidden="true"></span>
                @foreach(['n' => 'Сверху', 'e' => 'Справа', 's' => 'Снизу', 'w' => 'Слева', 'nw' => 'Верхний левый угол', 'ne' => 'Верхний правый угол', 'se' => 'Нижний правый угол', 'sw' => 'Нижний левый угол'] as $direction => $label)
                    <button
                        type="button"
                        class="venue-photo-crop__handle venue-photo-crop__handle--{{ $direction }}"
                        data-crop-handle="{{ $direction }}"
                        aria-label="Изменить кадрирование: {{ $label }}"
                    ></button>
                @endforeach
            </div>
        </div>
    </div>

    <div class="venue-photo-crop__toolbar">
        <div class="venue-photo-crop__controls" aria-label="Изменение масштаба кадра">
            <button type="button" x-on:click="zoom(1.2)" :disabled="busy" aria-label="Уменьшить масштаб изображения">−</button>
            <span>Масштаб</span>
            <button type="button" x-on:click="zoom(1 / 1.2)" :disabled="busy" aria-label="Увеличить масштаб изображения">+</button>
        </div>
        <button type="button" class="venue-photo-crop__reset" x-on:click="reset()" :disabled="busy">Сбросить рамку</button>
    </div>

    <div class="venue-photo-crop__footer">
        <p class="venue-photo-crop__status" role="status" aria-live="polite" x-text="status"></p>
        <button
            type="button"
            class="venue-photo-crop__apply"
            x-on:click="apply()"
            :disabled="busy"
            x-text="busy ? 'Применяем…' : 'Применить кадрирование'"
        ></button>
    </div>
    <p class="venue-photo-crop__hint">
        Оригинал не изменяется. Рамка сохраняется автоматически; до нажатия «Применить»
        посетители видят прежнюю фотографию.
    </p>
</div>
