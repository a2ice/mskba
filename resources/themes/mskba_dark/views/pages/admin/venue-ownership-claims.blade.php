@php $title = 'Заявки на владение площадками'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Проверка полномочий и выдача owner membership.',
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
            <h2 class="h4">№{{ $claim->id }} · {{ $claim->venue->name }}</h2>
            <p>Заявитель: {{ $claim->applicant->profile?->first_name ?: $claim->applicant->username ?: 'user #'.$claim->applicant->id }}</p>
            <p>Отправлена: {{ $claim->submitted_at->format('d.m.Y H:i') }}</p>
            <h3 class="h5 mt-3">Подтверждение</h3>
            <p>{!! nl2br(e($claim->evidence)) !!}</p>
            @if($claim->decision_reason)<p><strong>Причина решения:</strong> {{ $claim->decision_reason }}</p>@endif
            @if($claim->status === \App\Modules\Venue\Domain\Enums\VenueOwnershipClaimStatusEnum::PENDING)
                <div class="d-flex gap-3 flex-wrap mt-4">
                    <form method="POST" action="{{ route('admin.venue-ownership-claims.approve', $claim) }}">
                        @csrf
                        <input class="form-control mb-2" name="reason" maxlength="2000" placeholder="Комментарий (необязательно)">
                        <button class="btn btn--primary btn--sm" type="submit">Одобрить</button>
                    </form>
                    <form method="POST" action="{{ route('admin.venue-ownership-claims.reject', $claim) }}">
                        @csrf
                        <input class="form-control mb-2" name="reason" required minlength="5" maxlength="2000" placeholder="Причина отказа">
                        <button class="btn btn--secondary btn--sm" type="submit">Отклонить</button>
                    </form>
                </div>
            @endif
        </div></article>
    @empty
        <div class="admin-empty">Заявок с выбранным статусом нет.</div>
    @endforelse

    {{ $claims->links() }}
@endsection
