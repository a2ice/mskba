@php
    $creationRequirements = $requirements ?? [];
@endphp

@if(!empty($creationRequirements))
    <div class="creation-guide__requirements mb-3">
        <strong class="d-block mb-2">Условия создания</strong>
        <ul class="mb-0 ps-3">
            @foreach($creationRequirements as $requirement)
                <li class="mb-2">
                    {{ $requirement['text'] }}
                    @if(!empty($requirement['links']))
                        <span class="d-inline-flex flex-wrap gap-2 ms-1">
                            @foreach($requirement['links'] as $link)
                                @php
                                    $faqUrl = route($link['route']).(!empty($link['anchor']) ? '#'.$link['anchor'] : '');
                                @endphp
                                <a href="{{ $faqUrl }}" target="_blank" rel="noopener">
                                    {{ $link['label'] }} <i class="ti ti-external-link" aria-hidden="true"></i>
                                </a>
                            @endforeach
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
