@extends('theme::layouts.app', ['title' => $title ?? 'Админка'])

@section('content')
    <section class="admin-dashboard first-screen">
        <div class="inner">
            @yield('admin-dashboard-content')
        </div>
    </section>
@endsection
