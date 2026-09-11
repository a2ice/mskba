@extends('theme::layouts.section-sidebar', [
    'title' => 'Мои секции', 'sectionId' => 'account', 'sectionClass' => 'account-section',
    'contentTitle' => 'Мои секции', 'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false, 'sidebarPartial' => 'theme::partials.account.sidebar',
])
@section('section-heading-action')<a class="btn btn--primary btn--sm" href="{{ route('account.sports-sections.create') }}">Создать секцию</a>@endsection
@section('section-content')
<div class="account-sports-sections">
@forelse($sections as $section)<article><div><span class="text-accent">{{ $section->status->label() }}</span><h2>{{ $section->name }}</h2><p>{{ $section->game_format->label() }} · {{ $section->training_mode->label() }}</p></div><div><a class="btn btn--secondary btn--sm" href="{{ route('account.sports-sections.edit', $section) }}">Управлять</a>@if($section->status->value === 'active')<a class="btn btn--secondary btn--sm" href="{{ route('sports-sections.show', $section) }}">Открыть</a>@endif</div></article>
@empty<div class="alert alert-info">У вас пока нет секций.</div>@endforelse
</div>{{ $sections->links('theme::partials.pagination') }}
@endsection
