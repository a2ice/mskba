@php
    $authRedirectNotice = app(\App\Modules\Identity\Presentation\Http\Support\AuthenticationRedirectNotice::class)->for(request());
@endphp

@if($authRedirectNotice !== null)
    <div class="alert alert-info mb-3 auth-redirect-notice" role="status" data-auth-redirect-notice>
        <div class="auth-redirect-notice__title">
            <i class="ti ti-arrow-forward-up" aria-hidden="true"></i>
            <strong>После входа вернём вас к нужному действию</strong>
        </div>
        <div class="auth-redirect-notice__text">
            После успешного входа или регистрации вы автоматически перейдёте дальше: <strong>{{ mb_ucfirst($authRedirectNotice['label']) }}</strong>.
        </div>
    </div>
@endif
