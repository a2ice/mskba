@php
    $panelLayout = auth()->user()->canAccessPanel(filament()->getCurrentPanel())
        ? 'filament-panels::layout.index'
        : 'filament-panels::layout.base';
@endphp

<x-dynamic-component :component="$panelLayout">
    <div class="content-editor">
        <header class="fi-header fi-header-has-subheading">
            <div>
                <h1 class="fi-header-heading">{{ $title }}</h1>
                <p class="fi-header-subheading">{{ $subtitle }}</p>
            </div>
            <div class="fi-header-actions-ctn">
                @yield('section-heading-action')
            </div>
        </header>
        @yield('section-content')
    </div>
</x-dynamic-component>
