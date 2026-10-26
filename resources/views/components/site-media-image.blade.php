@props(['asset', 'alt' => '', 'loading' => 'lazy'])
@php
    $mediaAsset = $asset instanceof \App\Modules\Media\Domain\Models\SiteMediaAsset
        ? $asset
        : \App\Modules\Media\Domain\Models\SiteMediaAsset::query()
            ->where('uuid', (string) $asset)->first();
    $mediaVersion = $mediaAsset?->currentMedia;
    $lqip = $mediaAsset?->lqipUrl();
@endphp
@if ($mediaVersion)
    <span {{ $attributes->class(['mskba-site-media-image'])->merge(['data-site-media-progressive' => $lqip ? '1' : null]) }}
        style="aspect-ratio: {{ (int) ($mediaVersion->width ?: 1) }} / {{ (int) ($mediaVersion->height ?: 1) }};">
        @if ($lqip)
            <img class="mskba-site-media-image__lqip" src="{{ $lqip }}" alt=""
                aria-hidden="true" loading="{{ $loading }}" decoding="async"
                width="{{ $mediaVersion->width }}" height="{{ $mediaVersion->height }}">
        @endif
        <img class="mskba-site-media-image__original" src="{{ $mediaAsset->publicUrl() }}"
            alt="{{ $alt }}" loading="{{ $loading }}" decoding="async"
            width="{{ $mediaVersion->width }}" height="{{ $mediaVersion->height }}">
    </span>
@endif
