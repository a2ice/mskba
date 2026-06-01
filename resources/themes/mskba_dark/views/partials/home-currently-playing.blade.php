<aside class="home-live" aria-labelledby="home-live-title">
    <div class="home-live__panel">
        <div class="home-live__header">
            <p class="home-live__title" id="home-live-title">
                <span class="home-live__dot" aria-hidden="true"></span>
                Сейчас играют
            </p>
            <p class="home-live__summary">
                <span>12</span>
                <span>12 активных игр прямо сейчас</span>
            </p>
        </div>

        <div class="home-live__list">
            @foreach ($games as $game)
                <article class="home-live-card">
                    <img class="home-live-card__image" src="{{ $game['image'] }}" alt="">

                    <div class="home-live-card__content">
                        <div class="home-live-card__topline">
                            <p class="home-live-card__title">{{ $game['title'] }}</p>
                            <span @class([
                                'home-live-card__badge',
                                'is-live' => $game['badge_variant'] === 'live',
                                'is-soon' => $game['badge_variant'] === 'soon',
                            ])>{{ $game['badge'] }}</span>
                        </div>

                        <p class="home-live-card__venue">{{ $game['venue'] }}</p>

                        <div class="home-live-card__meta">
                            <span class="home-live-card__meta-item">
                                <span class="home-inline-icon home-inline-icon--clock" aria-hidden="true"></span>
                                {{ $game['time'] }}
                            </span>
                            <span class="home-live-card__meta-item">
                                <span class="home-inline-icon home-inline-icon--group" aria-hidden="true"></span>
                                {{ $game['players'] }}
                            </span>
                        </div>
                    </div>

                    <span class="home-live-card__arrow" aria-hidden="true">→</span>
                </article>
            @endforeach
        </div>

        <a class="home-live__all" href="#games">
            <span class="home-inline-icon home-inline-icon--calendar" aria-hidden="true"></span>
            <span>Смотреть все игры</span>
            <span class="home-live__all-arrow" aria-hidden="true">→</span>
        </a>

        <div class="home-live__stats">
            @foreach ($stats as $stat)
                <div class="home-live__stat">
                    <div class="home-live__stat-value">
                        <span class="home-inline-icon home-inline-icon--{{ $stat['icon'] }}" aria-hidden="true"></span>
                        <span>{{ $stat['value'] }}</span>
                    </div>
                    <div class="home-live__stat-label">{{ $stat['label'] }}</div>
                </div>
            @endforeach
        </div>
    </div>
</aside>
