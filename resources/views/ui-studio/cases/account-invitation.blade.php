{{-- Deterministic fixtures only; no model access, network or real submission. --}}
@php
    $previewMode = true;
    $started = !in_array($state, ['normal', 'expired', 'revoked'], true);
    $suggestedUsername = $state === 'collision' ? 'bookingdesk777' : 'bookingdesk';
    $currentUser = in_array($state, ['existing','accepted'], true) ? new class {
        public $username = 'venue_manager';
        public function isSameIdentity($id) { return $id === 1; }
    } : null;
    $invitation = new class($state) {
        public $title = 'Приглашение к подключению площадки';
        public $description = 'Присоединяйтесь к работе с заявками на аренду баскетбольного зала.';
        public $role = 'manager';
        public $scope_id = 1;
        public $contact_value = 'bookingdesk99@example.test';
        public $accepted_user_id = null;
        public $status = 'pending';
        public $progress = 'viewed';
        public $issuer;
        public $expires_at;
        public function __construct(public string $state) {
            $this->issuer = (object) ['username' => 'mskba_manager', 'nickname' => null];
            $this->expires_at = \Carbon\Carbon::parse('2026-12-03 18:00:00', 'Europe/Moscow');
            if ($state === 'revoked') $this->status = 'revoked';
            if ($state === 'accepted') { $this->accepted_user_id = 1; $this->progress = 'awaiting_account'; }
        }
        public function targetName() { return 'Баскетбольный зал «Пример»'; }
        public function statusLabel() { return match($this->state) { 'expired' => 'Просрочено', 'revoked' => 'Аннулировано', default => 'Ожидает завершения' }; }
        public function progressLabel() { return $this->state === 'accepted' ? 'Контакт подтверждён' : ($this->state === 'normal' ? 'Просмотрено' : 'Заинтересован'); }
        public function isExpired() { return $this->state === 'expired'; }
        public function canManageLink() { return $this->status === 'pending' && $this->accepted_user_id === null; }
    };
    $errors = new \Illuminate\Support\ViewErrorBag();
    if ($state === 'collision') $errors->put('default', new \Illuminate\Support\MessageBag(['username' => ['Предложенный логин изменился. Проверьте новый вариант.']]));
    $oldValue = fn ($key) => $state === 'custom' && in_array($key, ['custom_username','custom_password']) ? '1' : null;
    $link = fn ($name, $parameters = []) => '#';
    $portalContacts = [['value' => 'support@example.test', 'href' => '#'], ['value' => '+7 000 000-00-00', 'href' => '#']];
@endphp
@include('invitations.onboarding')
<script>
    document.querySelector('.app-invitation')?.addEventListener('submit', event => {
        event.preventDefault();
        const notice = document.createElement('p');
        notice.className = 'notice';
        notice.setAttribute('role','status');
        notice.textContent = 'Демонстрация: действие не отправлено. Следующее состояние можно выбрать в UI Studio.';
        event.target.after(notice);
    });
    document.querySelector('.app-invitation')?.addEventListener('click', event => {
        if (event.target.closest('a')) event.preventDefault();
    });
</script>
