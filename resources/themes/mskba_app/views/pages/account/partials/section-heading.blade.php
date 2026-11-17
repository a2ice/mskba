<section class="app-account-overview" aria-labelledby="account-section-title">
    <header class="app-account-overview__intro">
        <h1 id="account-section-title">{{ $heading }}</h1>
        @if (filled($subtitle ?? null))
            <p>{{ $subtitle }}</p>
        @endif
    </header>
</section>
@if (filled($intro ?? null))
    @push('account-intro')
        <section class="panel app-account-intro{{ (filled($introActionUrl ?? null) && filled($introActionLabel ?? null)) || isset($introConfirmation) ? ' app-account-intro--with-action' : '' }}" aria-label="О разделе">
            <p>{{ $intro }}</p>
            @if (isset($introConfirmation))
                <div class="app-account-intro__actions">
                    @if($introConfirmation['confirmed'])
                        <span class="app-account-intro__confirmed" aria-label="Статус: подтверждён">Подтверждён</span>
                        @if($introConfirmation['canRequestChange'] ?? false)
                            <button type="button" class="button secondary app-account-intro__request" data-player-change-request-open>Запросить изменение</button>
                        @endif
                    @else
                        <button type="submit" form="{{ $introConfirmation['formId'] }}"
                            class="button primary app-account-intro__confirm">Подтвердить</button>
                    @endif
                </div>
            @elseif (filled($introActionUrl ?? null) && filled($introActionLabel ?? null))
                <a class="button primary app-account-intro__action" href="{{ $introActionUrl }}">{{ $introActionLabel }}</a>
            @endif
        </section>
    @endpush
@endif
