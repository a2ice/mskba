<div class="home-highlights">
    @foreach ($items as $item)
        @php
            $iconClass = [
                'pin' => 'ti ti-map-pin',
                'group' => 'ti ti-users',
                'trophy' => 'ti ti-trophy',
                'shield' => 'ti ti-shield',
            ][$item['icon']] ?? 'ti ti-star';
        @endphp

        <article class="home-highlight">
            <div class="home-highlight__icon" aria-hidden="true">
                <i class="{{ $iconClass }}"></i>
            </div>
            <div class="home-highlight__content">
                <h2 class="home-highlight__title">{{ $item['title'] }}</h2>
                <p class="home-highlight__description">{{ $item['description'] }}</p>
            </div>
        </article>
    @endforeach
</div>
