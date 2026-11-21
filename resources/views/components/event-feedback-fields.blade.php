<div class="event-feedback-fields">
    @foreach(['discussion_enabled'=>'Разрешить обсуждение мероприятия','report_comments_enabled'=>'Разрешить комментарии после мероприятия'] as $feedbackKey=>$feedbackLabel)
        <input type="hidden" name="{{ $feedbackKey }}" value="0">
        <label class="check-label quick-event-dialog__game-toggle">
            <input type="checkbox" name="{{ $feedbackKey }}" value="1" @checked((bool) old($feedbackKey, ($feedbackEvent ?? null)?->{$feedbackKey} ?? true))>
            <span>{{ $feedbackLabel }}</span>
        </label>
    @endforeach
</div>
