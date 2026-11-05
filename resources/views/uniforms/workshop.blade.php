@extends('theme::layouts.app', ['title' => 'Мастерская баскетбольной формы', 'metaTitle' => 'Мастерская формы · MSKBA'])

@push('meta')
    <meta name="description" content="Попробуйте мастерскую формы MSKBA: подберите цвета, игровой номер и надпись для баскетбольной экипировки. Демонстрационная версия будущего конструктора.">
@endpush

@push('scripts')
    @vite('resources/themes/mskba_app/js/uniforms/uniform-entry.js')
@endpush

@section('content')
    <section class="mskba-uniform-page" aria-labelledby="mskba-uniform-page-title">
        <div class="mskba-uniform-page__inner">
            <a class="mskba-uniform-page__back" href="/#venues-story-uniforms">← Вернуться к истории MSKBA</a>
            <header class="mskba-uniform-page__intro">
                <span class="mskba-uniform-page__eyebrow">MSKBA / ТВОЯ КОМАНДА · ТВОЙ СТИЛЬ</span>
                <h1 id="mskba-uniform-page-title">Мастерская формы</h1>
                <p>Создайте первый эскиз игровой экипировки: выберите палитру, номер и надпись. В дальнейшем здесь появятся шаблоны, логотипы, размеры состава и оформление заказа у производителей.</p>
            </header>
            <div class="mskba-uniform-page__editor" data-mskba-uniform-workshop-page>
                <p>Загружаем мастерскую…</p>
            </div>
            <p class="mskba-uniform-page__bottom">Мастерская работает в демонстрационном режиме. Сохранение и изготовление формы ещё не доступны. <a href="/faq/faq-uniform-production">Как будет устроен заказ</a>.</p>
        </div>
    </section>
@endsection
