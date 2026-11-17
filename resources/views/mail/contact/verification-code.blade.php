<p>Код подтверждения контакта:</p>

<p><strong>{{ $code }}</strong></p>

<p>Код действует до {{ $verification->expires_at?->copy()->setTimezone('Europe/Moscow')->format('d.m.Y H:i') }} (МСК).</p>
