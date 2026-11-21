<!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>MSKBA UI Studio</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f4f6;color:#18212b;font:15px/1.5 system-ui,sans-serif}main{padding:24px;max-width:1800px;margin:auto}a{color:#a74500}header,form,.case{display:flex;gap:16px;flex-wrap:wrap;align-items:center}header{justify-content:space-between}form,.case{background:#fff;padding:16px;border:1px solid #ddd;border-radius:12px;margin-block:12px}label{display:grid;gap:4px}select,button{font:inherit;padding:8px;border:1px solid #aaa;border-radius:6px;background:#fff}button{cursor:pointer}h1,h2{margin:8px 0}.frames{display:flex;gap:16px;overflow:auto;align-items:flex-start;padding:12px 0}.frame{flex:none;max-width:none}.frame iframe{display:block;border:1px solid #aaa;background:white;height:80vh;min-height:480px}.case{justify-content:space-between}.muted{color:#59636f}code{overflow-wrap:anywhere}
</style></head>
<body><main>
<header><h1><a href="{{ route('ui-studio.show', absolute:false) }}">MSKBA UI Studio</a></h1><span>{{ $snapshot['source'] }} @if($snapshot['exported_at']) · {{ $snapshot['exported_at'] }} @endif</span></header>
@if(!$case)
    <h2>Интерфейсы</h2>
    @foreach($cases as $slug => $item)
        <div class="case"><a href="{{ route('ui-studio.show',['ui_name'=>$slug],false) }}">{{ $item['title'] }}</a><span>{{ $item['status'] }} · r{{ $item['revision'] }}</span><a href="https://github.com/a2ice/mskba/issues/{{ $item['issue'] }}">#{{ $item['issue'] }}</a></div>
    @endforeach
    <p><a href="https://github.com/a2ice/mskba/blob/main/docs/specification/ui-design-system.md">Гайдлайн проекта</a> · <a href="https://github.com/a2ice/mskba/issues/933">Регламент и развитие UI Studio</a></p>
@else
    <h2>{{ $case['title'] }}</h2><p>{{ $case['status'] }} · r{{ $case['revision'] }} · <a href="https://github.com/a2ice/mskba/issues/{{ $case['issue'] }}">#{{ $case['issue'] }}</a></p>
    <form method="get">
        <label>Шаблон<select name="template">@foreach($templates as $slug=>$item)<option value="{{ $slug }}" @selected($template===$slug)>{{ $item['name'] }} @if(!in_array($slug,$case['templates'],true)) — нет образца @endif</option>@endforeach</select></label>
        <label>Тема<select name="theme">@foreach($themes as $slug=>$item)<option value="{{ $slug }}" @selected($theme===$slug)>{{ $item['name'] }} ({{ $slug }})</option>@endforeach</select></label>
        <label>Сравнить с<select name="compare"><option value="">—</option>@foreach($themes as $slug=>$item)<option value="{{ $slug }}" @selected($compare===$slug)>{{ $item['name'] }}</option>@endforeach</select></label>
        <label>Ширина<select name="width">@foreach(['1440','768','390','360'] as $size)<option value="{{ $size }}" @selected($width===$size)>{{ $size }} px</option>@endforeach</select></label>
        <label>Состояние<select name="state">@foreach($case['states'] as $slug=>$name)<option value="{{ $slug }}" @selected($state===$slug)>{{ $name }}</option>@endforeach</select></label>
        <button>Применить</button>
    </form>
    @if(!$supported)
        <p>Для этого кодового шаблона пока нет образца. Эта комбинация не считается проверенной.</p>
    @else
        <div class="frames">
            @foreach(array_filter([$theme,$compare]) as $selected)
                <section class="frame"><p>{{ $themes[$selected]['name'] }} · {{ $width }} px · <code>{{ substr($themes[$selected]['hash'],0,12) }}</code></p>
                    <iframe title="{{ $themes[$selected]['name'] }}" width="{{ $width }}" src="{{ route('ui-studio.show',['ui_name'=>$ui_name,'embed'=>1,'theme'=>$selected,'template'=>$template,'state'=>$state,'width'=>$width],false) }}"></iframe>
                </section>
            @endforeach
        </div>
    @endif
@endif
</main></body></html>
