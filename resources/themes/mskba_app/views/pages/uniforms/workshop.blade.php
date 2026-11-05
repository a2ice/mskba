@extends('theme::layouts.app')

@section('content')
<section class="mskba-uniform-page" aria-label="Мастерская формы">
    <div class="mskba-uniform-page__container">
        <a href="{{ route('account.uniforms') }}" class="mskba-uniform-page__back">← Мои формы</a>
        <div class="mskba-uniform-page__heading">
            <span class="mskba-uniform-eyebrow">MSKBA / ЭКИПИРОВКА</span>
            <h1>Мастерская формы</h1>
            <p>Разработай свой комплект: цвет, размеры и логотип. Сохраняй варианты, возвращайся к ним и развивай дизайн вместе с MSKBA.</p>
        </div>
        <div data-mskba-uniform-page class="mskba-uniform-page__editor" aria-live="polite">
            <p>Загружаем мастерскую…</p>
        </div>
    </div>
</section>
@endsection
