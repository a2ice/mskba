@php
    $timeCommentEvent = $timeCommentEvent ?? null;
    $timeCommentDefault = \App\Modules\Event\Application\Services\EventTimeComment::defaultComment(auth()->user());
@endphp
<div class="field-group" data-event-time-comment-fields style="display:grid;gap:8px;margin:10px 0">
    <label class="field-group" style="display:grid;gap:5px">
        <span>Комментарий к предварительному времени</span>
        <textarea class="form-control" name="time_comment" rows="3" maxlength="2000">{{ old('time_comment', $timeCommentEvent?->time_comment ?? $timeCommentDefault) }}</textarea>
    </label>
    <small class="muted">Отображается в подсказке рядом с датой, пока время не подтверждено.</small>
    <label style="display:flex;align-items:flex-start;gap:8px">
        <input type="checkbox" name="save_time_comment_default" value="1" @checked(old('save_time_comment_default', false))>
        Использовать этот комментарий по умолчанию
    </label>
    @error('time_comment') <small role="alert">{{ $message }}</small> @enderror
</div>
