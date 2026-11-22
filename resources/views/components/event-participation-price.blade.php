@if($pricingEvent->is_paid && $pricingEvent->participant_cost_minor !== null)
    <div class="event-participation-price" style="display:flex;align-items:center;flex-wrap:wrap;gap:6px;position:relative;width:100%" data-event-participation-price>
        <strong>Стоимость участия: {{ number_format($pricingEvent->participant_cost_minor / 100, $pricingEvent->participant_cost_minor % 100 === 0 ? 0 : 2, ',', ' ') }} руб.</strong>
        @include('components.event-comment-tooltip', ['commentText' => $pricingEvent->cost_comment, 'commentLabel' => 'Комментарий к стоимости участия'])
    </div>
@endif
@if(!empty($pricingEvent->cost_inclusions))
    <div class="field-group" data-event-cost-inclusions>
        <strong>Что входит в оплату</strong>
        <ul style="margin:4px 0 0;padding-inline-start:20px">
            @foreach(\App\Modules\Event\Application\Services\EventParticipationPricing::INCLUSION_LABELS as $key => $label)
                @if(isset($pricingEvent->cost_inclusions[$key]))
                    @php($item = $pricingEvent->cost_inclusions[$key])
                    <li>{{ $key === 'custom' ? $item['title'] : $label }}@if(!empty($item['comment'])) — {{ $item['comment'] }}@endif</li>
                @endif
            @endforeach
        </ul>
    </div>
@endif
