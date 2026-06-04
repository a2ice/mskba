<p>Код подтверждения контакта:</p>

<p><strong>{{ $code }}</strong></p>

<p>Код действует до {{ $verification->expires_at?->format('d.m.Y H:i') }}.</p>
