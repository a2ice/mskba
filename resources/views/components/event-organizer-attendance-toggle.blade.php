<input type="hidden" name="organizer_participates" value="0">
<label class="check-label {{ $organizerToggleClass ?? '' }}">
    <input type="checkbox" name="organizer_participates" value="1" data-event-organizer-attendance
        @checked((string) old('organizer_participates', '1') === '1')>
    <span>Я буду участвовать в мероприятии</span>
</label>
