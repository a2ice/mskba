<input type="hidden" name="event_time_only" value="1">
<input type="hidden" name="venue_id" value="{{ $event->venue_id }}">
<p class="muted">Меняется только дата и время мероприятия. Связанная аренда остаётся без изменений.</p>
<div class="field-group">
    <label for="eventStartsAt">Начало</label>
    <input id="eventStartsAt" type="datetime-local" class="form-control" name="starts_at"
        value="{{ old('starts_at', $event->starts_at->setTimezone($timezone)->format('Y-m-d\TH:i')) }}" required>
</div>
<div class="field-group">
    <label for="eventDuration">Длительность</label>
    <select id="eventDuration" class="form-control" name="duration_minutes" required>
        @foreach(collect($durationOptions)->push($currentDuration)->unique()->sort()->values() as $minutes)
            <option value="{{ $minutes }}" @selected((int) old('duration_minutes', $currentDuration) === $minutes)>{{ $minutes }} мин.</option>
        @endforeach
    </select>
</div>
