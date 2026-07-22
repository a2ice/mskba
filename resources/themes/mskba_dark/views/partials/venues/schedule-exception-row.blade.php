@php
    $exceptionIntervals = array_pad(array_slice($exception['intervals'] ?? [], 0, 3), 3, ['starts_at' => '', 'ends_at' => '']);
    $isClosed = filter_var($exception['is_closed'] ?? false, FILTER_VALIDATE_BOOL);
@endphp
<section class="account-venue-schedule-exception" data-schedule-exception>
    <div class="account-venue-schedule-exception__head">
        <label><span>Дата</span><input type="date" name="exceptions[{{ $index }}][date]" class="form-control" value="{{ $exception['date'] ?? '' }}" required></label>
        @error("exceptions.$index.date")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        <label class="account-venue-schedule-exception__closed"><input type="hidden" name="exceptions[{{ $index }}][is_closed]" value="0"><input type="checkbox" name="exceptions[{{ $index }}][is_closed]" value="1" @checked($isClosed) data-schedule-exception-closed> Закрыто весь день</label>
        <button type="button" class="btn btn--secondary btn--sm" data-schedule-exception-remove>Удалить дату</button>
    </div>
    <div class="account-venue-schedule-exception__intervals" data-schedule-exception-intervals @if($isClosed) hidden @endif>
        @foreach($exceptionIntervals as $intervalIndex => $interval)
            <div class="account-venue-schedule-interval">
                <div class="account-venue-schedule-interval__label">Интервал {{ $intervalIndex + 1 }}</div>
                <label><span>Начало</span><input type="time" name="exceptions[{{ $index }}][intervals][{{ $intervalIndex }}][starts_at]" class="form-control" value="{{ $interval['starts_at'] ?? '' }}"></label>
                <label><span>Конец</span><input type="time" name="exceptions[{{ $index }}][intervals][{{ $intervalIndex }}][ends_at]" class="form-control" value="{{ $interval['ends_at'] ?? '' }}"></label>
            </div>
            @error("exceptions.$index.intervals.$intervalIndex.starts_at")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            @error("exceptions.$index.intervals.$intervalIndex.ends_at")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        @endforeach
        @error("exceptions.$index.intervals")<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>
</section>
