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
        <section class="panel app-account-intro{{ filled($introActionUrl ?? null) && filled($introActionLabel ?? null) ? ' app-account-intro--with-action' : '' }}" aria-label="О разделе">
            <p>{{ $intro }}</p>
            @if (filled($introActionUrl ?? null) && filled($introActionLabel ?? null))
                <a class="button primary app-account-intro__action" href="{{ $introActionUrl }}">{{ $introActionLabel }}</a>
            @endif
        </section>
    @endpush
@endif
