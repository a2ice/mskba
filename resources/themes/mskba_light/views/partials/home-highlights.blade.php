<div class="home-highlights">
    @foreach ($items as $item)
        <article class="home-highlight">
            <div class="home-highlight__icon home-inline-icon home-inline-icon--{{ $item['icon'] }}" aria-hidden="true"></div>
            <div class="home-highlight__content">
                <h2 class="home-highlight__title">{{ $item['title'] }}</h2>
                <p class="home-highlight__description">{{ $item['description'] }}</p>
            </div>
        </article>
    @endforeach
</div>
