@if($pricingEvent->is_paid && $pricingEvent->participant_cost_minor !== null)
    <div class="event-participation-price" style="display:flex;align-items:center;flex-wrap:wrap;gap:6px;position:relative;width:100%" data-event-participation-price>
        <strong>Стоимость участия: {{ number_format($pricingEvent->participant_cost_minor / 100, $pricingEvent->participant_cost_minor % 100 === 0 ? 0 : 2, ',', ' ') }} руб.</strong>
        @include('components.event-comment-tooltip', ['commentText' => $pricingEvent->cost_comment, 'commentLabel' => 'Комментарий к стоимости участия'])
    </div>
@endif
