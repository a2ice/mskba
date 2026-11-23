<!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>MSKBA UI Studio</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f4f6;color:#18212b;font:15px/1.5 system-ui,sans-serif}main{padding:24px;max-width:1800px;margin:auto}a{color:#a74500}header,form,.case{display:flex;gap:16px;flex-wrap:wrap;align-items:center}header{justify-content:space-between}form,.case{background:#fff;padding:16px;border:1px solid #ddd;border-radius:12px;margin-block:12px}label{display:grid;gap:4px}select,button{font:inherit;padding:8px;border:1px solid #aaa;border-radius:6px;background:#fff}button{cursor:pointer}h1,h2{margin:8px 0}.frames{display:flex;gap:16px;overflow:auto;align-items:flex-start;padding:12px 0}.frame{flex:none;max-width:none}.frame iframe{box-sizing:content-box;display:block;border:1px solid #aaa;background:white;height:80vh;min-height:480px}.case{justify-content:space-between}.muted{color:#59636f}code{overflow-wrap:anywhere}
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
                    <iframe title="{{ $themes[$selected]['name'] }}" width="{{ $width }}" src="{{ route('ui-studio.show',['ui_name'=>$ui_name,'embed'=>1,'theme'=>$selected,'theme_hash'=>$themes[$selected]['hash'],'template'=>$template,'state'=>$state,'width'=>$width],false) }}"></iframe>
                </section>
            @endforeach
        </div>
    @endif
@endif

@if($case && $supported)
<section class="case" style="display:block" aria-labelledby="studio-review-heading">
    <h3 id="studio-review-heading">Приёмка интерфейса · ревизия {{ $case['revision'] }}</h3>
    <p class="muted">Проверены состояния, разрешения, темы, desktop и mobile? Принятие фиксирует именно эту ревизию и снимок темы, но не публикует изменения в production.</p>
    @if($acceptedReview)
        <p role="status"><strong>Принято к интеграции</strong> · {{ $acceptedReview['accepted_at'] }} · UI Kit: {{ count($acceptedReview['kit_selected']) }} согласовано, {{ count($acceptedReview['kit_declined']) }} оставлено локально.</p>
    @else
        <button id="studio-review-open" type="button">Принять интерфейс</button>
    @endif
</section>
<dialog id="studio-review-dialog" aria-labelledby="studio-review-title" style="border:1px solid #ccc;border-radius:16px;padding:24px;max-width:min(600px,calc(100vw - 28px));width:100%;box-shadow:0 12px 40px #0003">
    <h3 id="studio-review-title">Обновление UI Kit</h3>
    <p class="muted">Интерфейс принят к интеграции после явного подтверждения. Выберите предложенные общие изменения. Отмеченные будут включены в план обновления UI Kit; неотмеченные останутся локальными. Сам код переносится только после проверки PR и CI.</p>
    @if(count($case['kit_changes'] ?? []) > 0)
        @foreach($case['kit_changes'] as $change)
            <label style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-top:1px solid #ddd">
                <input type="checkbox" name="kit-change" value="{{ $change['id'] }}" checked style="margin-top:5px">
                <span><strong>{{ $change['title'] }}</strong><br><small class="muted">{{ $change['description'] }}</small></span>
            </label>
        @endforeach
    @else
        <p>Новых общих компонентов не обнаружено. Сохранится только принятая ревизия интерфейса.</p>
    @endif
    <p id="studio-review-error" role="alert" style="color:#a11" hidden></p>
    <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:16px">
        <button id="studio-review-confirm" type="button">Принять и сохранить решение</button>
        <button id="studio-review-cancel" type="button">Вернуться к проверке</button>
    </div>
</dialog>
<script>
(() => {
    const open = document.getElementById('studio-review-open');
    const dialog = document.getElementById('studio-review-dialog');
    if (!open || !dialog) return;
    const confirm = document.getElementById('studio-review-confirm');
    const error = document.getElementById('studio-review-error');
    open.addEventListener('click', () => dialog.showModal());
    document.getElementById('studio-review-cancel').addEventListener('click', () => dialog.close());
    confirm.addEventListener('click', async () => {
        error.hidden = true;
        confirm.disabled = true;
        try {
            const response = await fetch(@json(route('ui-studio.review', absolute:false)), {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':@json(csrf_token())},
                body: JSON.stringify({
                    case:@json($ui_name),revision:@json($case['revision']),
                    fingerprint:@json($fingerprint),theme_hash:@json($themes[$theme]['hash']),
                    selected:[...dialog.querySelectorAll('input[name="kit-change"]:checked')].map(el=>el.value)
                })
            });
            if (!response.ok) throw new Error('HTTP ' + response.status + ' · перепроверьте страницу и авторизацию через Filament');
            window.location.reload();
        } catch(e) {
            error.textContent = 'Не удалось сохранить решение: ' + e.message;
            error.hidden = false;
            confirm.disabled = false;
        }
    });
})();
</script>
@endif
</main></body></html>
