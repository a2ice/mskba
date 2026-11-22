@if($pricingEvent->is_paid && $pricingEvent->participant_cost_minor !== null)
    <div class="event-participation-price" style="display:flex;align-items:center;flex-wrap:wrap;gap:6px" data-event-participation-price>
        <strong>Стоимость участия: {{ number_format($pricingEvent->participant_cost_minor / 100, $pricingEvent->participant_cost_minor % 100 === 0 ? 0 : 2, ',', ' ') }} руб.</strong>
        @if(filled($pricingEvent->cost_comment))
            <details style="position:relative;display:inline-block">
                <summary aria-label="Комментарий к стоимости участия" style="cursor:pointer;list-style:none;display:grid;place-items:center;width:22px;height:22px;border:1px solid currentColor;border-radius:50%;font-size:14px;line-height:1">?</summary>
                <div role="note" style="position:absolute;z-index:10;right:0;top:calc(100% + 6px);width:min(260px,70vw);padding:12px;background:var(--surface,#fff);color:var(--text,#181c23);border:1px solid var(--line,#ccc);border-radius:12px;box-shadow:0 8px 28px #0002;font-size:14px;font-weight:normal;white-space:normal;overflow-wrap:anywhere">{{ $pricingEvent->cost_comment }}</div>
            </details>
        @endif
    </div>
@endif
