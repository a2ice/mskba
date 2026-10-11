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
        <section class="panel app-account-intro" aria-label="О разделе">
            <p>{{ $intro }}</p>
        </section>
    @endpush
@endif
