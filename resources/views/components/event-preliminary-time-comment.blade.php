@if(\App\Modules\Event\Application\Services\EventTimeComment::isPreliminary($timeCommentEvent))
    @include('components.event-comment-tooltip', [
        'commentText' => $timeCommentEvent->time_comment ?: \App\Modules\Event\Application\Services\EventTimeComment::DEFAULT_COMMENT,
        'commentLabel' => 'Время предварительное — комментарий организатора',
    ])
@endif
