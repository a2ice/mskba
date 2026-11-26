<h2>Приглашение принято</h2>
<p>Площадка: <strong>{{ $venueName }}</strong>.</p>
<p>Email для входа: <strong>{{ $email }}</strong>.</p>
@if($username)
    <p>Логин: <strong>{{ $username }}</strong>.</p>
@endif
@if($generatedPassword !== null)
    <p>Ваш системный пароль: <strong>{{ $generatedPassword }}</strong>.</p>
    <p>Рекомендуем установить собственный пароль в настройках аккаунта.</p>
@endif
<p><a href="{{ $loginUrl }}">Войти на портал MSKBA</a></p>
@if($role === 'owner')
    <p>В личном кабинете подайте заявку на подтверждение управления площадкой. Полномочия владельца появятся после проверки.</p>
@else
    <p>Завершите подтверждение аккаунта в личном кабинете. Полномочия предоставляются после выполнения условий подключения.</p>
@endif
@if($portalContacts !== [])
    <p>Контакты MSKBA:</p>
    @foreach($portalContacts as $contact)
        <p><a href="{{ $contact['href'] }}">{{ $contact['value'] }}</a></p>
    @endforeach
@endif
