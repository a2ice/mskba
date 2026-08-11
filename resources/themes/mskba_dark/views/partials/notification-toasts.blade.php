@auth
    <section
        class="notification-toast-region"
        data-notification-toasts
        data-notification-user-id="{{ auth()->id() }}"
        data-notification-sync-url="{{ route('account.notifications.new') }}"
        aria-label="Новые уведомления"
        aria-live="polite"
        aria-relevant="additions"
    ></section>
@endauth
