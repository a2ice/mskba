@php
    $title = 'Управление площадкой';
    $ownerName = null;
    if ($owner) {
        $ownerName = trim(implode(' ', array_filter([
            $owner->profile?->first_name,
            $owner->profile?->last_name,
        ]))) ?: $owner->username;
    }
    $ownershipUnderReview = $currentOwnership?->status === \App\Modules\Venue\Domain\Enums\VenueOwnershipStatusEnum::UNDER_REVIEW;
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="first-screen venue-ownership-page">
        <div class="inner venue-ownership-shell">
            @include('theme::partials.breadcrumbs')

            <div class="venue-ownership-heading">
                <p class="venue-ownership-eyebrow">ПЛОЩАДКА · УПРАВЛЕНИЕ</p>
                <h1>{{ $title }}</h1>
                <div class="venue-ownership-venue-row">
                    <div>
                        <strong>{{ $venue->name }}</strong>
                        @if($venue->rawAddress)
                            <span>{{ $venue->rawAddress }}</span>
                        @endif
                    </div>
                    <a class="fc-link" href="{{ route('venues.show', $venue->routeIdentifier()) }}">Открыть площадку →</a>
                </div>
            </div>

            @if(session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif
            @if(session('info'))
                <div class="alert alert-info">{{ session('info') }}</div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif

            @if($owner)
                <article class="venue-ownership-card {{ $ownershipUnderReview ? 'venue-ownership-card--review' : 'venue-ownership-card--confirmed' }}">
                    <div class="venue-ownership-status-icon"><i class="ti {{ $ownershipUnderReview ? 'ti-shield-question' : 'ti-shield-check' }}"></i></div>
                    <div>
                        <p class="venue-ownership-card__eyebrow">{{ $ownershipUnderReview ? 'СТАТУС УТОЧНЯЕТСЯ' : 'УПРАВЛЕНИЕ ПОДТВЕРЖДЕНО' }}</p>
                        <h2>{{ $ownershipUnderReview ? 'Полномочия представителя уточняются' : 'У площадки есть подтверждённый представитель' }}</h2>
                        @if($ownershipUnderReview)
                            <p>Администрация MSKBA уточняет основания управления. На это время права управления площадкой приостановлены.</p>
                            @if($currentOwnership?->status_reason)
                                <p><strong>Причина:</strong> {{ $currentOwnership->status_reason }}</p>
                            @endif
                        @else
                            <p>Управление этой площадкой на MSKBA уже подтверждено.</p>
                        @endif
                        <div class="venue-ownership-owner">
                            @include('theme::partials.avatar', ['user' => $owner, 'size' => 'md'])
                            <div>
                                <span>Подтверждённый представитель</span>
                                <strong>{{ $ownerName ?: 'Пользователь MSKBA' }}</strong>
                            </div>
                        </div>
                    </div>
                </article>
            @else
                <article class="venue-ownership-card">
                    <p class="venue-ownership-card__eyebrow">ПОДТВЕРЖДЕНИЕ ПОЛНОМОЧИЙ</p>
                    <h2>Вы управляете этой площадкой?</h2>
                    <p>
                        Если вы владелец площадки или официальный представитель владельца и можете подтвердить
                        полномочия, подайте заявку. После проверки вы получите права управления площадкой на MSKBA.
                    </p>
                    <div class="venue-ownership-points">
                        <span><i class="ti ti-check"></i> редактирование данных и расписания</span>
                        <span><i class="ti ti-check"></i> управление коммерческими ролями и арендой</span>
                        <span><i class="ti ti-check"></i> статус подтверждённого представителя площадки</span>
                    </div>

                    @guest
                        <button
                            type="button"
                            class="btn btn--primary"
                            data-handler="modal"
                            data-modal-action="open"
                            data-modal-target="auth-entry-classic"
                            data-auth-redirect-url="{{ route('venues.management', $venue, false) }}"
                        >
                            Войти и подтвердить управление
                        </button>
                    @else
                        @if($restriction)
                            <div class="venue-ownership-inline-state venue-ownership-inline-state--danger">
                                <div>
                                    <span>Повторная подача ограничена</span>
                                    <strong>Для вашего аккаунта заблокированы заявки на управление этой площадкой.</strong>
                                    <small>{{ $restriction->reason }}</small>
                                </div>
                            </div>
                        @elseif($pendingClaim)
                            <div class="venue-ownership-inline-state">
                                <div>
                                    <span>Ваша заявка</span>
                                    <strong>{{ $pendingClaim->status->label() }}</strong>
                                </div>
                                <a class="btn btn--primary btn--sm" href="{{ route('account.venue-ownership.show', $pendingClaim) }}">
                                    Открыть заявку
                                </a>
                            </div>
                        @elseif($needsAccountConfirmation)
                            <div class="venue-ownership-inline-state">
                                <div>
                                    <span>Перед подачей заявки</span>
                                    <strong>Нужно подтвердить аккаунт или основной контакт</strong>
                                </div>
                                <a class="btn btn--primary btn--sm" href="{{ route('venues.management.verify', $venue) }}">
                                    Подтвердить данные
                                </a>
                            </div>
                        @elseif($canSubmitClaim)
                            <form
                                id="claim-form"
                                method="POST"
                                action="{{ route('venues.management.claim', $venue) }}"
                                class="venue-ownership-form"
                            >
                                @csrf
                                <label for="ownershipEvidence">
                                    <span>Как вы связаны с площадкой и чем можете подтвердить полномочия?</span>
                                    <small>Укажите организацию, должность, рабочие контакты или реквизиты подтверждающих документов. Не отправляйте пароли и другие секретные данные.</small>
                                </label>
                                <textarea
                                    id="ownershipEvidence"
                                    name="evidence"
                                    class="form-control"
                                    rows="6"
                                    required
                                    minlength="20"
                                    maxlength="5000"
                                    placeholder="Например: являюсь администратором спорткомплекса, могу подтвердить полномочия с корпоративной почты…"
                                >{{ old('evidence') }}</textarea>
                                @error('evidence')<div class="alert alert-danger mt-2">{{ $message }}</div>@enderror

                                <div class="venue-ownership-inline-state mt-3">
                                    <div>
                                        <span>Документы пока не требуются</span>
                                        <small>Если для проверки понадобятся файлы, администратор запросит их в защищённой переписке по заявке.</small>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn--primary mt-3">Подать заявку</button>
                            </form>
                        @endif
                    @endguest
                </article>
            @endif

            @auth
                @if($claimHistory->isNotEmpty())
                    <section class="venue-ownership-history">
                        <div class="venue-ownership-history__head">
                            <p class="venue-ownership-card__eyebrow">ИСТОРИЯ</p>
                            <h2>Ваши заявки по этой площадке</h2>
                        </div>
                        <div class="venue-ownership-history__list">
                            @foreach($claimHistory as $claim)
                                <a href="{{ route('account.venue-ownership.show', $claim) }}" class="venue-ownership-history__item">
                                    <span>{{ $claim->submitted_at->format('d.m.Y H:i') }}</span>
                                    <strong>{{ $claim->status->label() }}</strong>
                                    <i class="ti ti-arrow-right"></i>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif
            @endauth
        </div>
    </section>
@endsection
