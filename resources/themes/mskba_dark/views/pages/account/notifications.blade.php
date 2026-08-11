@php
use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
$notificationPresenter = app(\App\Modules\Notification\Presentation\Presenters\UserNotificationPresenter::class);
@endphp

@php $title = 'Центр уведомлений'; @endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])

@section('section-content')

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
                <div data-notification-read-all>
                    <form method="POST" action="{{ route('account.notifications.read-all') }}" class="mb-4">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn btn--secondary-bordered btn--sm"><span class="fs-lil-smaller">Отметить все прочитанными</span></button>
                    </form>
                    <hr>
                </div>
            @endif

            <ul class="list-unstyled mb-0">
                @foreach($notifications as $notification)
                    @php
                        $notificationView = $notificationPresenter->present($notification);
                        $statusIcon = $notification->isNew() ? 'ti-bell-ringing' : 'ti-check';
                        $typeIcon = match ($notification->type) {
                            UserNotificationTypeEnum::SYSTEM => 'ti-settings',
                            UserNotificationTypeEnum::SECURITY => 'ti-shield-lock',
                            UserNotificationTypeEnum::PROFILE => 'ti-user-circle',
                            UserNotificationTypeEnum::REMINDER => 'ti-clock',
                        };
                    @endphp
                    <li
                        class="account-notification-item mb-4 {{ $notification->isNew() ? 'is-new' : 'is-read' }}"
                        data-notification-card="{{ $notification->id }}"
                    >
                        <div class="d-flex flex-wrap justify-content-between align-center gap-2 mb-2">
                            <div class="account-notification-item__badges">
                                <span
                                    class="account-notification-badge account-notification-badge--status {{ $notification->isNew() ? 'is-new' : 'is-read' }}"
                                    title="{{ $notification->status->label() }}"
                                    data-tooltip-variant="title"
                                    aria-label="{{ $notification->status->label() }}"
                                >
                                    <i class="ti {{ $statusIcon }}" aria-hidden="true"></i>
                                </span>
                                <span
                                    class="account-notification-badge account-notification-badge--type"
                                    title="{{ $notification->type->label() }}"
                                    data-tooltip-variant="title"
                                    aria-label="{{ $notification->type->label() }}"
                                >
                                    <i class="ti {{ $typeIcon }}" aria-hidden="true"></i>
                                </span>
                            </div>
                            <span class="text-muted fc-link">{{ $notification->created_at->format('d.m.Y H:i') }}</span>
                        </div>

                        <a href="{{ $notificationView['href'] }}" class="account-notification-item__content fc-link">
                            <h5 class="h5 mb-2"><span class="fs-lil-smaller">{{ $notification->title }}</span></h5>
                        </a>
                        <p class="mb-3">{{ $notification->body }}</p>

                        <div class="d-flex flex-wrap gap-2">
                            @foreach($notificationView['actions'] as $action)
                                <form method="POST" action="{{ $action['url'] }}">
                                    @csrf
                                    @method($action['method'])
                                    <input type="hidden" name="decision" value="{{ $action['key'] }}">
                                    <button type="submit" class="btn btn--{{ $action['variant'] }} btn--sm">{{ $action['label'] }}</button>
                                </form>
                            @endforeach

                            @if($notification->isNew())
                                <form method="POST" action="{{ route('account.notifications.read', $notification) }}" data-notification-read-action>
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
