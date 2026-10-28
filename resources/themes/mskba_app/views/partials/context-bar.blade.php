@php
    $accountIsUnconfirmed = auth()->check()
        && auth()->user()->canonical()->status === \App\Modules\Identity\Domain\Enums\UserStatusEnum::UNCONFIRMED;
    $isHomePage = request()->routeIs('welcome', 'home');
@endphp
{{-- Home keeps its clean header for guests and confirmed users. Only an
     unconfirmed member gets the minimal contextual Actions bar there. --}}
@if (! $isHomePage || $accountIsUnconfirmed)
    @php
        $trail = $isHomePage ? [] : app(\App\Presentation\Breadcrumbs\BreadcrumbsResolver::class)->resolve($title ?? null, $breadcrumbs ?? null);
        $contextItems = $isHomePage ? [] : app(\App\Presentation\Navigation\ContextSubmenuResolver::class)->resolve();
        $parentUrl = collect(array_slice($trail, 0, -1))->reverse()->first(fn (array $crumb): bool => ! empty($crumb['url']))['url'] ?? route('welcome');
        $showAccountConfirmation = $accountIsUnconfirmed && ! request()->routeIs('account.confirmation');
        $showOverviewConfirmationGuide = $accountIsUnconfirmed && request()->routeIs('account');
    @endphp
    <div class="app-context-bar" data-app-context-bar>
        <div class="container app-context-bar__inner">
            @if ($isHomePage)
                <span class="app-context-home-label">Главная</span>
            @else
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
            <nav class="app-context-path" aria-label="Путь страницы">
                <details data-app-context-path>
                    <summary class="app-context-quick app-context-path__trigger" aria-label="Показать путь страницы">
                        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor"
                             stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 5h6a3 3 0 0 1 0 6H9a3 3 0 0 0 0 6h10"/>
                            <circle cx="5" cy="5" r="2"/><circle cx="19" cy="17" r="2"/>
                        </svg>
                    </summary>
                    <ol class="app-context-path__menu">
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
                </details>
            </nav>
            @endif
            <div class="app-context-bar__controls">
                @unless ($isHomePage)
                <button type="button" class="app-context-quick app-context-back" data-app-context-back
                        data-fallback="{{ $parentUrl }}" aria-label="Назад">
                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor"
                         stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m14 6-6 6 6 6" />
                    </svg>
                    <span class="app-context-quick__label">Назад</span>
                </button>
                @endunless
                <details class="app-context-actions" data-app-context-actions>
                    <summary class="app-context-quick app-context-actions__trigger" aria-label="Действия">
                        <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor"
                             stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                        <span class="app-context-quick__label">Действия</span>
                        @if ($showAccountConfirmation)
                            <span class="app-context-actions__attention" aria-hidden="true"></span>
                        @endif
                    </summary>
                    <div class="app-context-actions__menu" aria-label="Контекстные действия">
                        @if ($contextItems !== [])
                            @include('theme::partials.context-actions-items', ['items' => $contextItems])
                            <div class="app-context-actions__separator" role="separator"></div>
                        @endif
                        @if ($showAccountConfirmation)
                            <a class="app-context-actions__verify" href="{{ route('account.confirmation') }}"
                               aria-label="Подтвердить аккаунт">
                                <span class="app-context-actions__attention" aria-hidden="true"></span>
                                Подтвердить акк...
                            </a>
                            <div class="app-context-actions__separator" role="separator"></div>
                        @endif
                        <button type="button" data-app-context-share>Поделиться</button>
                        <button type="button" data-context-help data-help-context="{{ app(\App\Presentation\Navigation\FaqContextResolver::class)->context() }}">Помощь</button>
                    </div>
                </details>
                <button type="button" class="app-context-quick app-context-header-pin"
                        data-app-header-pin aria-pressed="false"
                        aria-label="Показать и закрепить шапку" title="Показать и закрепить шапку">
                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor"
                         stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m6 9 6 6 6-6"/>
                    </svg>
                </button>
            </div>
            <span class="app-context-bar__status" role="status" aria-live="polite" data-app-context-status></span>
        </div>
    </div>
    @include('theme::partials.context-action-dialogs')
    @if ($showOverviewConfirmationGuide)
        @include('theme::partials.account.confirmation-guide-dialog', [
            'accountConfirmationGuideHtml' => app(\App\Modules\Content\Application\Services\WelcomeAccountConfirmationGuide::class)->html(),
        ])
    @endif
@endif
