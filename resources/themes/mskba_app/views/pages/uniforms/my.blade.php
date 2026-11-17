@extends('theme::layouts.account', ['title' => 'Мои формы'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Мои формы',
    ])
@endsection

@section('account-content')
    <section class="mskba-account-uniforms" aria-label="Мои формы">
        <div data-mskba-uniform-list aria-live="polite">
            <p>Загружаем коллекцию…</p>
        </div>
    </section>
@endsection
