@php
    $title = 'Управление владениями';
    $queueLabels = [
        'new' => 'Новые',
        'in_work' => 'В работе',
        'completed' => 'Завершённые',
    ];
    $displayName = static function ($user): string {
        if (! $user) return 'Пользователь MSKBA';
        return trim(implode(' ', array_filter([
            $user->profile?->first_name,
            $user->profile?->last_name,
        ]))) ?: ($user->username ?: 'Пользователь #'.$user->id);
    };
@endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Заявки на подтверждение управления, активные владения, основания и изменения статуса.',
])

@section('section-content')
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    <section class="ownership-admin-section">
        <div class="ownership-admin-section__head">
            <div>
                <p class="venue-ownership-card__eyebrow">ЗАЯВКИ</p>
                <h2>Подтверждение управления</h2>
            </div>
            <div class="ownership-admin-tabs" role="tablist" aria-label="Статус заявок">
                @foreach($queueLabels as $value => $label)
                    <a
                        class="ownership-admin-tab {{ $queue === $value ? 'is-active' : '' }}"
                        href="{{ route('admin.venue-ownership.index', array_filter(['queue' => $value, 'ownership_status' => $ownershipStatus->value])) }}"
                    >{{ $label }}</a>
                @endforeach
            </div>
        </div>

        <div class="ownership-admin-list">
            @forelse($claims as $claim)
                @php
                    $claimApplicant = $displayName($claim->applicant);
                    $workState = $claim->status === \App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum::PENDING
                        ? ($claim->conversation ? 'В работе' : 'Новая')
                        : $claim->status->label();
                @endphp
                <article class="ownership-admin-card">
                    <div class="ownership-admin-card__main">
                        <div class="ownership-admin-card__top">
                            <span class="ownership-admin-status ownership-admin-status--{{ $claim->status->value }}">{{ $workState }}</span>
                            <span>{{ $claim->submitted_at?->format('d.m.Y H:i') }}</span>
                        </div>
                        <h3>{{ $claim->venue->name }}</h3>
                        <div class="ownership-admin-person">
                            @include('theme::partials.avatar', ['user' => $claim->applicant, 'size' => 'sm'])
                            <div>
                                <small>Заявитель</small>
                                <strong>{{ $claimApplicant }}</strong>
                            </div>
                        </div>
                        <p class="ownership-admin-evidence">{{ \Illuminate\Support\Str::limit($claim->evidence, 220) }}</p>
                    </div>
                    <div class="ownership-admin-card__actions">
                        <a class="btn btn--primary btn--sm" href="{{ route('account.venue-ownership.show', $claim) }}">
                            {{ $claim->status === \App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum::PENDING ? 'Рассмотреть' : 'Открыть' }}
                        </a>
                    </div>
                </article>
            @empty
                <div class="admin-empty">В этой группе заявок сейчас нет.</div>
            @endforelse
        </div>
        <div class="mt-4">{{ $claims->links() }}</div>
    </section>

    <section class="ownership-admin-section ownership-admin-section--ownerships">
        <div class="ownership-admin-section__head">
            <div>
                <p class="venue-ownership-card__eyebrow">ВЛАДЕНИЯ</p>
                <h2>Подтверждённые представители</h2>
            </div>
            <div class="ownership-admin-tabs" role="tablist" aria-label="Статус владений">
                @foreach($ownershipStatuses as $status)
                    <a
                        class="ownership-admin-tab {{ $ownershipStatus === $status ? 'is-active' : '' }}"
                        href="{{ route('admin.venue-ownership.index', ['queue' => $queue, 'ownership_status' => $status->value]) }}"
                    >{{ $status->label() }}</a>
                @endforeach
            </div>
        </div>

        <div class="ownership-admin-list">
            @forelse($ownerships as $ownership)
                <article class="ownership-admin-card ownership-admin-card--ownership">
                    <div class="ownership-admin-card__main">
                        <div class="ownership-admin-card__top">
                            <span class="ownership-admin-status ownership-admin-status--{{ $ownership->status->value }}">{{ $ownership->status->label() }}</span>
                            <span>{{ $ownership->approved_at?->format('d.m.Y H:i') }}</span>
                        </div>
                        <h3>{{ $ownership->venue->name }}</h3>
                        <div class="ownership-admin-person">
                            @include('theme::partials.avatar', ['user' => $ownership->owner, 'size' => 'sm'])
                            <div>
                                <small>Подтверждённый представитель</small>
                                <strong>{{ $displayName($ownership->owner) }}</strong>
                            </div>
                        </div>
                        @if($ownership->status_reason)
                            <p><strong>Причина текущего статуса:</strong> {{ $ownership->status_reason }}</p>
                        @endif
                        <div class="ownership-admin-meta">
                            <span><i class="ti ti-files"></i> Оснований: {{ $ownership->documents->count() }}</span>
                            @if($ownership->sourceClaim)
                                <a href="{{ route('account.venue-ownership.show', $ownership->sourceClaim) }}">Исходная заявка →</a>
                            @endif
                        </div>

                        @if($ownership->documents->isNotEmpty())
                            <div class="ownership-admin-documents">
                                @foreach($ownership->documents as $document)
                                    <a href="{{ route('admin.venue-ownership.documents.download', $document) }}" class="ownership-admin-document">
                                        <i class="ti ti-file"></i>
                                        <span>{{ $document->type->label() }} · {{ $document->name }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    @if($ownership->status !== \App\Modules\Venue\Domain\Enums\VenueOwnershipStatusEnum::REVOKED)
                        <form method="POST" action="{{ route('admin.venue-ownership.status', $ownership) }}" class="ownership-admin-status-form">
                            @csrf
                            @method('PATCH')
                            <label>
                                <span>Новый статус</span>
                                <select name="status" class="form-select" required>
                                    @foreach($ownershipStatuses as $status)
                                        <option value="{{ $status->value }}" @selected($ownership->status === $status)>{{ $status->label() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>
                                <span>Причина изменения</span>
                                <textarea name="reason" class="form-control" rows="3" required minlength="5" maxlength="3000" placeholder="Почему меняется статус владения?"></textarea>
                            </label>
                            <button class="btn btn--secondary btn--sm" type="submit">Сохранить статус</button>
                        </form>
                    @else
                        <div class="ownership-admin-card__actions">
                            <span class="text-muted">Аннулированное владение хранится в истории и не восстанавливается.</span>
                        </div>
                    @endif
                </article>
            @empty
                <div class="admin-empty">Владений с этим статусом нет.</div>
            @endforelse
        </div>
        <div class="mt-4">{{ $ownerships->links() }}</div>
    </section>
@endsection
