@php
    use App\Modules\Acquisition\Domain\Enums\AcquisitionCampaignStateEnum;

    $title = $campaignState->title();
    $detail = match ($campaignState) {
        AcquisitionCampaignStateEnum::SCHEDULED => $campaign->starts_at
            ? 'Кампания начнётся '.$campaign->starts_at->format('d.m.Y').' в '.$campaign->starts_at->format('H:i').'.'
            : 'Кампания ещё не началась.',
        AcquisitionCampaignStateEnum::ENDED => $campaign->ends_at
            ? 'Кампания завершилась '.$campaign->ends_at->format('d.m.Y').' в '.$campaign->ends_at->format('H:i').'.'
            : 'Кампания уже завершена.',
        AcquisitionCampaignStateEnum::INACTIVE => 'Организатор временно отключил эту кампанию.',
        default => '',
    };
@endphp

@extends('theme::layouts.app', [
    'title' => $title.' · MSKBA',
    'metaDescription' => 'Статус промо-кампании MSKBA.',
])

@section('content')
    <section class="acquisition-campaign-state">
        <div class="inner acquisition-campaign-state__inner">
            <div class="section-card acquisition-campaign-state__card">
                <span class="acquisition-campaign-state__eyebrow">MSKBA</span>
                <h1>{{ $title }}</h1>
                <p>{{ $detail }}</p>
                <p class="acquisition-campaign-state__campaign">{{ $campaign->name }}</p>
                <a href="{{ route('welcome') }}" class="btn btn--primary">Перейти на MSKBA</a>
            </div>
        </div>
    </section>
@endsection
