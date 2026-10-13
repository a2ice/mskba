@extends('theme::layouts.app', ['title' => 'Заявки на изменение профиля'])
@section('content')
<section class="app-account-section">
  <div class="container">
    <h1>Заявки на изменение профиля</h1>

    @if (session('success'))
        <div class="notice" role="status">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="notice" role="alert">{{ $errors->first() }}</div>
    @endif
    <p>Решения принимаются только уполномоченным администратором. Отказ допускает повторную подачу, блокировка — нет, до разблокировки.</p>
    <div class="app-profile-change-review-list">
        @forelse ($requests as $item)
            <section class="panel app-profile-change-review" aria-labelledby="profile-request-{{ $item->id }}">
                <h2 id="profile-request-{{ $item->id }}">Заявка №{{ $item->id }} — {{ $item->user?->username ?? ('#'.$item->user_id) }}</h2>
                <p><strong>Статус:</strong> {{ [
                    'pending' => 'На рассмотрении', 'approved' => 'Одобрена',
                    'rejected' => 'Отклонена', 'blocked' => 'Заблокирована',
                    'cancelled' => 'Отменена',
                ][$item->status] ?? $item->status }}</p>
                <p><a class="button secondary" href="{{ route('account.notifications.threads.profile', $item->id) }}">Открыть обсуждение</a></p>
                <p><strong>Причина заявителя:</strong> {{ $item->reason }}</p>
                @if ($item->review_reason)
                    <p><strong>Решение модератора:</strong> {{ $item->review_reason }}</p>
                @endif
                @if ($item->unblocked_at)
                    <p><strong>Разблокирована:</strong> {{ $item->unblocked_at->format('d.m.Y H:i') }}</p>
                @endif
                <table class="app-profile-change-review__table">
                    <thead><tr><th>Поле</th><th>Было</th><th>Предлагается</th></tr></thead>
                    <tbody>
                        @foreach (['first_name' => 'Имя', 'last_name' => 'Фамилия', 'birth_date' => 'Дата рождения', 'gender' => 'Пол'] as $field => $label)
                            <tr>
                                <th>{{ $label }}</th>
                                <td>{{ $item->original_data[$field] ?? '—' }}</td>
                                <td>{{ $item->proposed_data[$field] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($item->status === 'pending')
                    <div class="app-profile-change-review__actions">
                        <form method="POST" action="{{ route('admin.profile-change-requests.review', $item) }}">
                            @csrf
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="button primary">Одобрить</button>
                        </form>
                        <form method="POST" action="{{ route('admin.profile-change-requests.review', $item) }}">
                            @csrf
                            <input type="hidden" name="action" value="reject">
                            <label>Причина отклонения <textarea name="review_reason" required maxlength="2000" rows="2"></textarea></label>
                            <button type="submit" class="button secondary">Отклонить</button>
                        </form>
                        <form method="POST" action="{{ route('admin.profile-change-requests.review', $item) }}">
                            @csrf
                            <input type="hidden" name="action" value="block">
                            <label>Причина блокировки <textarea name="review_reason" required maxlength="2000" rows="2"></textarea></label>
                            <button type="submit" class="button secondary">Заблокировать</button>
                        </form>
                    </div>
                @elseif ($item->status === 'blocked')
                    <form method="POST" action="{{ route('admin.profile-change-requests.review', $item) }}">
                        @csrf
                        <input type="hidden" name="action" value="unblock">
                        <button type="submit" class="button secondary">Разблокировать подачу заявок</button>
                    </form>
                @endif
            </section>
        @empty
            <p>Заявок пока нет.</p>
        @endforelse
    </div>
    {{ $requests->links() }}

  </div>
</section>
@endsection
