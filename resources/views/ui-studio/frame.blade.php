<!doctype html>
<html lang="ru" data-design-theme="{{ $theme }}" data-appearance-mode="{{ $themes[$theme]['mode'] }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><title>{{ $case['title'] }}</title>
@if($supported && $template === 'mskba_app')
    @vite(['resources/themes/mskba_app/css/app.css'])
    <link rel="stylesheet" href="{{ route('ui-studio.asset',['slug'=>$theme,'hash'=>$themes[$theme]['hash'],'kind'=>'css'],false) }}">
    @if($themes[$theme]['js'] !== '')<script defer src="{{ route('ui-studio.asset',['slug'=>$theme,'hash'=>$themes[$theme]['hash'],'kind'=>'js'],false) }}"></script>@endif
@elseif($supported)
    @vite(['resources/themes/'.$template.'/css/app.css'])
@endif
</head>
<body>
@if($supported)
    @if($template === 'mskba_app')
        {!! \Illuminate\Support\Facades\View::file(resource_path('themes/mskba_app/views/partials/icons.blade.php'))->render() !!}
    @endif
    @include('ui-studio.cases.'.$ui_name)
@else
    <p>Образец для этого шаблона недоступен.</p>
@endif
</body></html>
