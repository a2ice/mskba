@php
    $rawCounterId = config('services.yandex_metrika.id');
    $counterId = is_int($rawCounterId) || (is_string($rawCounterId) && ctype_digit($rawCounterId))
        ? (int) $rawCounterId
        : null;
@endphp

@if ($counterId !== null && $counterId > 0)
    <noscript>
        <div>
            <img src="https://mc.yandex.ru/watch/{{ $counterId }}" style="position:absolute; left:-9999px;" alt="">
        </div>
    </noscript>
@endif
