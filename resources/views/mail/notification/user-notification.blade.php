<h1>{{ $notification->title }}</h1>

<p>{{ $notification->body }}</p>

@if($notification->action_url)
    <p><a href="{{ url($notification->action_url) }}">{{ $notification->action_text ?: 'Открыть' }}</a></p>
@endif

<p>Это уведомление отправлено порталом MSKBA. Настроить email-уведомления можно в настройках аккаунта.</p>
