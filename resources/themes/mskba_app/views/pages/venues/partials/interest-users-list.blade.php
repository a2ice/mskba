    <div class="app-venue-interests-dialog__list">
        @foreach([
            ['label' => 'Подписаны на эту площадку', 'count' => $venueAudience['direct_count'], 'players' => $venueAudience['direct']],
            ['label' => 'Подходят по условиям', 'count' => $venueAudience['matched_count'], 'players' => $venueAudience['matched']],
        ] as $group)
            @if($group['count'] > 0)
            <section class="app-venue-interests-dialog__group">
                <div class="app-venue-interests-dialog__heading"><span>{{ $group['label'] }}</span><span>{{ $group['count'] }}</span></div>
                @foreach($group['players'] as $player)
                    <div class="app-venue-interests-dialog__player {{ !$player['public'] ? 'app-venue-interests-dialog__private' : '' }}">
                        <span class="app-venue-interest-summary__avatar">
                            @if($player['avatar'])<img src="{{ $player['avatar'] }}" alt="" loading="lazy">
                            @elseif(!$player['public'])<svg class="app-venue-interest-summary__profile-icon" aria-hidden="true"><use href="#user"/></svg>
                            @else<span aria-hidden="true">{{ mb_strtoupper(mb_substr($player['name'], 0, 1)) }}</span>@endif
                        </span>
                        <div class="app-venue-interests-dialog__details">
                            <strong>{{ $player['is_self'] ? 'Это вы' : $player['name'] }}</strong>
                            @if(count($player['positions']))<span>{{ implode(' · ', $player['positions']) }}</span>@endif
                            @if($player['experience_years'] !== null)<span>Игровой опыт: {{ $player['experience_years'] }} лет</span>@endif
                        </div>
                        @if($player['can_view_details'])
                            <button type="button" class="button secondary app-venue-interests-dialog__more"
                                data-venue-player-details-open>Подробнее</button>
                            <template data-venue-player-template>
                                <div class="app-venue-player-details__identity">
                                    <span class="app-venue-interest-summary__avatar app-venue-player-details__avatar">
                                        @if($player['avatar'])
                                            <img src="{{ $player['avatar'] }}" alt="">
                                        @else
                                            <svg class="app-venue-interest-summary__profile-icon" aria-hidden="true"><use href="#user"/></svg>
                                        @endif
                                    </span>
                                    <div>
                                        <strong>{{ $player['is_self'] ? 'Это вы' : $player['name'] }}</strong>
                                        @if($player['is_self'])<p class="muted">{{ $player['name'] }}</p>@endif
                                    </div>
                                </div>
                                <dl class="app-venue-player-details__facts">
                                    @if(count($player['positions']))
                                        <div><dt>Игровые позиции</dt><dd>{{ implode(', ', $player['positions']) }}</dd></div>
                                    @endif
                                    @if($player['experience_years'] !== null)
                                        <div><dt>Игровой опыт</dt><dd>{{ $player['experience_years'] }} лет</dd></div>
                                    @endif
                                    @if($player['height_cm'] !== null)
                                        <div><dt>Рост</dt><dd>{{ $player['height_cm'] }} см</dd></div>
                                    @endif
                                    @if($player['weight_kg'] !== null)
                                        <div><dt>Вес</dt><dd>{{ $player['weight_kg'] }} кг</dd></div>
                                    @endif
                                    @if($player['body_type'])
                                        <div><dt>Телосложение</dt><dd>{{ $player['body_type'] }}</dd></div>
                                    @endif
                                </dl>
                                @if(count($player['skills']))
                                    <h3>Самооценка игровых навыков</h3>
                                    <dl class="app-venue-player-details__skills">
                                        @foreach($player['skills'] as $skill)
                                            <div><dt>{{ $skill['label'] }}</dt><dd>{{ $skill['value'] }}</dd></div>
                                        @endforeach
                                    </dl>
                                @elseif(!count($player['positions']) && $player['experience_years'] === null && $player['height_cm'] === null && $player['weight_kg'] === null && !$player['body_type'])
                                    <p class="muted">Игровые характеристики пока не заполнены.</p>
                                @endif
                            </template>
                        @endif
                    </div>
                @endforeach
            </section>
            @endif
        @endforeach
    </div>
