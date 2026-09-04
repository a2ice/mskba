@php $title = 'Подтверждение управления площадками'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Проверка полномочий и выдача подтверждённого OWNER membership.',
])

@section('section-content')
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    <form method="GET" action="{{ route('admin.venue-ownership-claims.index') }}" class="admin-filter mb-4">
        <label class="admin-filter__field"><span class="admin-filter__label">Статус</span>
            <select class="form-select" name="status">
                @foreach($statuses as $status)
                    <option value="{{ $status->value }}" @selected($selectedStatus === $status)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </label>
        <button class="btn btn--secondary btn--sm" type="submit">Показать</button>
    </form>

    @forelse($claims as $claim)
        <article class="card mb-4"><div class="card-body">
            <div class="d-flex justify-content-between gap-3 flex-wrap align-items-start">
                <div>
                    <h2 class="h4">{{ $claim->venue->name }}</h2>
                    <p>Заявитель: {{ trim(($claim->applicant->profile?->first_name ?? '').' '.($claim->applicant->profile?->last_name ?? '')) ?: $claim->applicant->username ?: 'user #'.$claim->applicant->id }}</p>
                    <p>Отправлена: {{ $claim->submitted_at->format('d.m.Y H:i') }}</p>
                </div>
                <a class="btn btn--primary btn--sm" href="{{ route('account.venue-ownership.show', $claim) }}">
                    Открыть заявку и переписку
                </a>
            </div>
            <h3 class="h5 mt-3">Подтверждение</h3>
            <p>{!! nl2br(e($claim->evidence)) !!}</p>
            @if($claim->decision_reason)<p><strong>Причина решения:</strong> {{ $claim->decision_reason }}</p>@endif
            @if($claim->status === \App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum::PENDING)
                <p class="text-muted mt-3 mb-0">Решение и переписка доступны на странице заявки.</p>
            @endif
        </div></article>
    @empty
        <div class="admin-empty">Заявок с выбранным статусом нет.</div>
    @endforelse

    {{ $claims->links() }}
@endsection
