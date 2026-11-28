<section class="panel app-community-panel">
    <h2>{{ $block['title'] }}</h2>
    <ul class="app-community-list">
        @foreach($block['items'] as $entry)
            <li>@if(!empty($entry['url']))<a href="{{ $entry['url'] }}">{{ $entry['name'] }}</a>@else{{ $entry['name'] }}@endif</li>
        @endforeach
    </ul>
</section>
