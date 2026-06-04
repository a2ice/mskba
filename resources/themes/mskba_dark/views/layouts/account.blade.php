@php
    $title = $title ?? 'Аккаунт';
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section id="account" class="account-section first-screen">
        <div class="inner">

            <div class="mb-3">
                @include('theme::partials.breadcrumbs')
            </div>

            <div class="section-content">

                <div class="row">
                    <div class="col-md-3">
                        <div class="card mb-4">
                            <div class="card-body">                                    
                                @include('theme::partials.avatar', ['page' => 'account'])
                            </div>
                        </div>
                        <div class="card mb-4">
                            <div class="card-body">                               
                                @include('theme::partials.menu.sidebar', ['page' => 'account'])
                            </div>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <div class="card mb-4">
                            <div class="card-body">
                                <div class="px-3">
                                    <div class="section-content-heading">
                                        <h3 class="mb-4">{{ $title }}</h3>
                                    </div>

                                    @yield('account-content')
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection