@unless (request()->routeIs('welcome', 'home'))
    @php
        $trail = app(\App\Presentation\Breadcrumbs\BreadcrumbsResolver::class)->resolve($title ?? null, $breadcrumbs ?? null);
        $contextItems = app(\App\Presentation\Navigation\ContextSubmenuResolver::class)->resolve();
        $parentUrl = collect(array_slice($trail, 0, -1))->reverse()->first(fn (array $crumb): bool => ! empty($crumb['url']))['url'] ?? route('welcome');
        $accountIsUnconfirmed = auth()->check()
            && auth()->user()->canonical()->status === \App\Modules\Identity\Domain\Enums\UserStatusEnum::UNCONFIRMED;
        $showAccountConfirmation = $accountIsUnconfirmed
            && request()->routeIs('account', 'account.*')
            && ! request()->routeIs('account.confirmation');
        $showOverviewConfirmationGuide = $accountIsUnconfirmed && request()->routeIs('account');
    @endphp
    <div class="app-context-bar" data-app-context-bar>
        <div class="container app-context-bar__inner">
            <nav class="app-context-trail" aria-label="Навигационная цепочка">
                <ol>
                    @foreach ($trail as $crumb)
                        <li>
                            @if (! $loop->last && ! empty($crumb['url']))
                                <a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
                            @else
                                <span @if ($loop->last) aria-current="page" @endif>{{ $crumb['label'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </nav>
            <details class="app-context-actions" data-app-context-actions>
                <summary>
                    @if ($showAccountConfirmation)
                        <span class="app-context-actions__attention" aria-hidden="true"></span>
                    @endif
                    Действия
                    <svg aria-hidden="true"><use href="#chevron-down"/></svg>
                </summary>
                <div class="app-context-actions__menu" aria-label="Контекстные действия">
                    @if ($showAccountConfirmation)
                        <a class="app-context-actions__verify" href="{{ route('account.confirmation') }}"
                           aria-label="Подтвердить аккаунт">
                            <span class="app-context-actions__attention" aria-hidden="true"></span>
                            Подтвердить акк...
                        </a>
                        <div class="app-context-actions__separator" role="separator"></div>
                    @endif
                    @if ($contextItems !== [])
                        @include('theme::partials.context-actions-items', ['items' => $contextItems])
                        <div class="app-context-actions__separator" role="separator"></div>
                    @endif
                    <button type="button" data-app-context-back data-fallback="{{ $parentUrl }}">Назад</button>
                    <button type="button" data-app-context-share>Поделиться</button>
                    <a href="{{ url('/faq') }}">Помощь</a>
                </div>
            </details>
            <span class="app-context-bar__status" role="status" aria-live="polite" data-app-context-status></span>
        </div>
    </div>
    @if ($showOverviewConfirmationGuide)
        @include('theme::partials.account.confirmation-guide-dialog', [
            'accountConfirmationGuideHtml' => app(\App\Modules\Content\Application\Services\WelcomeAccountConfirmationGuide::class)->html(),
        ])
    @endif
@endunless
