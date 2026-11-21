<div class="studio-feedback-fields">
    <label><input type="checkbox" data-feedback-discussion @checked($discussionEnabled ?? true)><span>Разрешить обсуждение мероприятия<small>До завершения мероприятия.</small></span></label>
    <label><input type="checkbox" data-feedback-comments @checked($reportCommentsEnabled ?? true)><span>Разрешить комментарии после мероприятия<small>В разделе «Как это было».</small></span></label>
</div>