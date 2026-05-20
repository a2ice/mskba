@extends('theme::layouts.app', ['title' => $title ?? 'Панель администратора'])

@section('content')
<section class="first-screen">
    <div class="inner">
        <div class="section-header">
            <h1 class="section-title">Панель администратора</h1>
            @include('theme::partials.breadcrumbs')
        </div>
        <div class="section-content">
            <div class="grid gap-8 lg:grid-cols-[150px_minmax(0,1fr)]">
                <aside>
                    @include('theme::partials.innermenu.adminmainmenu')
                </aside>
                <div class="min-w-0">
                    @yield('admin_content')
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
