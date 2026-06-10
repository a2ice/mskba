@php $title = 'Админка'; @endphp

@extends('theme::layouts.admin-dashboard', ['title' => $title])

@section('admin-dashboard-content')
    <div class="admin-dashboard__header">
        <div>
            <div class="admin-dashboard__eyebrow mb-3">Админка</div>
            <h1 class="layout-content-title admin-dashboard__title">Панель управления</h1>
            <p class="admin-dashboard__subtitle">
                Операционный центр проекта: пользователи, площадки, будущие события, команды, контент и настройки.
            </p>
        </div>
        <a href="{{ route('welcome') }}" class="btn btn--secondary btn--sm">На сайт</a>
    </div>

    <div class="admin-dashboard__grid">
        @foreach($tiles as $tile)
            @if(!empty($tile['hideOnDashboard']))
                @continue
            @endif
            <a href="{{ $tile['url'] }}" class="admin-tile">
                <span class="admin-tile__icon">
                    <i class="ti {{ $tile['icon'] }}"></i>
                </span>
                <span class="admin-tile__body">
                    <div class="admin-tile__title">{{ $tile['label'] }}</div>
                    <span class="admin-tile__description">{{ $tile['description'] }}</span>
                    <span class="admin-tile__meta">
                        @if(isset($tile['data']['count']) && $tile['data']['count'] !== null)
                            {{ $tile['data']['count'] }}
                        @else
                            {{ $tile['status'] ?? 'Запланировано' }}
                        @endif
                    </span>
                </span>
            </a>
        @endforeach
    </div>
@endsection
