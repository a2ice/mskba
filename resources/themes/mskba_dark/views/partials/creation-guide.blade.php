@php
    $helpTopic = \App\Presentation\Creation\CreationPages::TOPICS[request()->route()?->getName()] ?? null;
    $helpGuide = $helpTopic ? config('creation-guides.'.$helpTopic) : null;
@endphp
@if($helpGuide)
    <details class="creation-guide mb-4">
        <summary><i class="ti ti-help" aria-hidden="true"></i><span>{{ $helpGuide['heading'] }}</span><i class="ti ti-chevron-down creation-guide__arrow" aria-hidden="true"></i></summary>
        <div class="creation-guide__body">
            <p>{{ $helpGuide['intro'] }}</p>
            @include('theme::partials.creation-requirements', ['requirements' => $helpGuide['requirements'] ?? []])
            <a href="{{ route('faq.creation', ['topic' => $helpTopic]) }}" target="_blank" rel="noopener">Полная инструкция в FAQ <i class="ti ti-external-link" aria-hidden="true"></i></a>
        </div>
    </details>
@endif
