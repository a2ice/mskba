{{-- Only an active, resolved catalogue landing may display editorial HTML. --}}
@if(config('features.venue_catalog_landings.enabled', false) && isset($landing) && $landing instanceof \App\Modules\Content\Domain\Models\VenueCatalogLanding && $landing->is_active && (int) ($editorialPage ?? 1) === 1)
    @php
        $editorialHtml = app(\App\Modules\Content\Application\Services\ContentBodySanitizer::class)
            ->sanitize((string) ($editorialPosition === 'intro' ? $landing->intro_text : $landing->body_text));
    @endphp
    @if(trim($editorialHtml) !== '')
        <div class="{{ $editorialClass }}" data-venue-landing-editorial="{{ $editorialPosition }}">
            {!! $editorialHtml !!}
        </div>
    @endif
@endif
