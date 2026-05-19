@extends('theme::layouts.app', ['title' => $title ?? 'Панель администратора'])

@section('content')
<section class="first-screen">
    <div class="inner">
        <div class="section-header">
            <h1 class="section-title">Панель администратора</h1>
            @include('theme::partials.breadcrumbs')
        </div>
        <div class="section-content">
            <div class="flex flex-row gap-4">
                <aside class="col-3">
                    @include('theme::partials.innermenu.adminmainmenu')
                </aside>
                <div class="col-9">
                    @yield('admin_content')
                </div>
            </div>
        </div>
    </div>
</section>
@endsection