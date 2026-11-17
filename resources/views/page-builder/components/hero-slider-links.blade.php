{{-- Reuse the legacy responsive highlight-rail contract and CSS. --}}
<nav class="mskba-hero-slide__highlights mskba-hero-slide__highlights--layers"
    aria-label="{{ $element['name'] }}">
    <div class="mskba-hero-slide__highlights-viewport">
        <div class="mskba-hero-slide__highlights-track">
            <div class="mskba-hero-slide__highlights-group">
                @foreach ($element['links'] as $link)
                    @include('page-builder.components.hero-slider-link-item', ['link' => $link, 'index' => $loop->index, 'duplicate' => false])
                @endforeach
            </div>
            <div class="mskba-hero-slide__highlights-group mskba-hero-slide__highlights-group--duplicate" aria-hidden="true" inert>
                @foreach ($element['links'] as $link)
                    @include('page-builder.components.hero-slider-link-item', ['link' => $link, 'index' => $loop->index, 'duplicate' => true])
                @endforeach
            </div>
        </div>
    </div>
</nav>
