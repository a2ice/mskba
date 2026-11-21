<!doctype html>
<html lang="ru" data-design-theme="{{ $theme }}" data-appearance-mode="{{ $themes[$theme]['mode'] }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><title>{{ $case['title'] }}</title>
@if($supported)
    @vite(['resources/themes/mskba_app/css/app.css'])
    <link rel="stylesheet" href="{{ route('ui-studio.asset',['slug'=>$theme,'kind'=>'css'],false) }}">
    @if($themes[$theme]['js'] !== '')<script defer src="{{ route('ui-studio.asset',['slug'=>$theme,'kind'=>'js'],false) }}"></script>@endif
@endif
</head>
<body>
@if($supported)
    @include('ui-studio.cases.'.$ui_name) 
@else
    <p>Образец для этого шаблона недоступен.</p>
@endif
</body></html>
