{{-- Public landings or an explicitly authorized, private editorial preview. --}}
@if(isset($landing) && $landing instanceof \App\Modules\Content\Domain\Models\VenueCatalogLanding && (($isLandingPreview ?? false) || (config('features.venue_catalog_landings.enabled', false) && $landing->is_active)) && (int) ($editorialPage ?? 1) === 1)
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
