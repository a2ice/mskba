@php
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;

@endphp

@extends('theme::layouts.account', [
    'title' => 'Центр уведомлений',
])

@section('account-content')

    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

    @if(session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    @if(isset($notifications) && $notifications->isNotEmpty())
        <div class="account-notifications fs-smaller">
            @if(($newNotificationsCount ?? 0) > 0)
                <form method="POST" action="{{ route('account.notifications.read-all') }}" class="mb-4">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn--secondary-bordered btn--sm"><span class="fs-lil-smaller">Отметить все прочитанными</span></button>
                </form>
                <hr>
            @endif

            <ul class="list-unstyled mb-0">
                @foreach($notifications as $notification)
                    @php
                        $statusIcon = $notification->isNew() ? 'ti-bell-ringing' : 'ti-check';
                        $typeIcon = match ($notification->type) {
                            UserNotificationTypeEnum::SYSTEM => 'ti-settings',
                            UserNotificationTypeEnum::SECURITY => 'ti-shield-lock',
                            UserNotificationTypeEnum::PROFILE => 'ti-user-circle',
                            UserNotificationTypeEnum::REMINDER => 'ti-clock',
                        };
                    @endphp
                    <li class="account-notification-item mb-4 {{ $notification->isNew() ? 'is-new' : 'is-read' }}">
                        <div class="d-flex flex-wrap justify-content-between align-center gap-2 mb-2">
                            <div class="account-notification-item__badges">
                                <span
                                    class="account-notification-badge account-notification-badge--status {{ $notification->isNew() ? 'is-new' : 'is-read' }}"
                                    title="{{ $notification->status->label() }}"
                                    aria-label="{{ $notification->status->label() }}"
                                >
                                    <i class="ti {{ $statusIcon }}" aria-hidden="true"></i>
                                </span>
                                <span
                                    class="account-notification-badge account-notification-badge--type"
                                    title="{{ $notification->type->label() }}"
                                    aria-label="{{ $notification->type->label() }}"
                                >
                                    <i class="ti {{ $typeIcon }}" aria-hidden="true"></i>
                                </span>
                            </div>
                            <span class="text-muted fc-link">{{ $notification->created_at->format('d.m.Y H:i') }}</span>
                        </div>

                        <h5 class="h5 mb-2"><span class="fs-lil-smaller">{{ $notification->title }}</span></h5>
                        <p class="mb-3">{{ $notification->body }}</p>

                        <div class="d-flex flex-wrap gap-2">
                            @if($notification->action_url)
                                <a href="{{ $notification->action_url }}" class="btn btn--secondary btn--sm">{{ $notification->action_text ?: 'Открыть' }}</a>
                            @endif

                            @if($notification->isNew())
                                <form method="POST" action="{{ route('account.notifications.read', $notification) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn--primary btn--sm">Прочитано</button>
                                </form>
                            @endif
                        </div>

                        @if(!$loop->last)
                            <hr class="mt-4 mb-0">
                        @endif
                    </li>
                @endforeach
            </ul>

            <div class="mt-4">
                {{ $notifications->links() }}
            </div>
        </div>
    @else
        <p class="mb-0">Уведомлений пока нет.</p>
    @endif

@endsection
