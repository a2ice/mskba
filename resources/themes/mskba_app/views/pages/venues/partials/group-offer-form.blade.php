<details class="app-group-offers__create" @if($errors->any()) open @endif>
<summary class="button secondary">Создать промокод</summary>
<p class="muted">Создание сохраняет черновик. Отдельное действие «Опубликовать» откроет код подписчикам.</p>
<form method="POST" action="{{ route('account.venues.group-offers.store',['venue'=>$venue->id]) }}" class="app-group-offers__form">
@csrf
<label>Название <input name="title" maxlength="120" required value="{{ old('title') }}"></label>
<label>Промокод <input name="code" minlength="5" maxlength="32" pattern="[A-Za-z0-9_-]+" required autocomplete="off" value="{{ old('code') }}"></label>
<label>Тип скидки <select name="discount_type">
<option value="percent">Проценты</option><option value="fixed">Фиксированная сумма (копейки)</option>
</select></label>
<label>Размер скидки (% или копейки) <input type="number" name="discount_value" min="1" required value="{{ old('discount_value') }}"></label>
<label>Начало ({{ $venueTimezone }}) <input type="datetime-local" name="valid_from" required value="{{ old('valid_from') }}"></label>
<label>Окончание (пусто = бессрочно) <input type="datetime-local" name="valid_until" value="{{ old('valid_until') }}"></label>
<label>Зона аренды <select name="scope">
<option value="">Любая</option>
@forelse(\App\Modules\Event\Domain\Enums\VenueBookingScopeEnum::cases() as $scope)
<option value="{{ $scope->value }}">{{ $scope->label() }}</option>
@empty
@endforelse
</select></label>
<label>Зал <select name="venue_court_id">
<option value="">Любой</option>
@forelse($offerCourts as $court)
<option value="{{ $court->id }}">{{ $court->name }}</option>
@empty
@endforelse
</select></label>
<label>Минимальная сумма (копейки) <input type="number" name="minimum_amount_minor" min="0" value="{{ old('minimum_amount_minor') }}"></label>
<label>Лимит использований <input type="number" name="max_total_uses" min="1" value="{{ old('max_total_uses') }}"></label>
<label>Лимит на аккаунт <input type="number" name="max_uses_per_account" min="1" value="{{ old('max_uses_per_account') }}"></label>
<label class="app-group-offers__wide">Описание условий <textarea name="description" rows="3" maxlength="1000">{{ old('description') }}</textarea></label>
<button class="button primary" type="submit">Сохранить черновик</button>
</form>
</details>
