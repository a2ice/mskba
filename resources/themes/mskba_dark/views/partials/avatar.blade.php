@php
    $user = !empty($user) ? $user : auth()->user();
    $displayName = '';
    if($user) {
        if($user?->profile?->first_name) {
            $displayName = trim($user->profile->first_name . ($user->profile?->last_name ? ' ' . $user->profile->last_name : ''));
        } else {
            $displayName = $user->username;
        }
    }

    $gender = $user?->profile?->gender;

    $placeholderUrl = match ($gender) {
        \App\Modules\Identity\Domain\Enums\UserGenderEnum::FEMALE => asset('images/blank/avatar/avatar-female.png'),
        \App\Modules\Identity\Domain\Enums\UserGenderEnum::MALE => asset('images/blank/avatar/avatar-male.png'),
        default => asset('images/blank/avatar/avatar-male.png'),
    };

    $profile = $user?->profile;
    $activeAvatar = $profile
        ? ($profile->relationLoaded('activeAvatar') ? $profile->activeAvatar : $profile->activeAvatar()->first())
        : null;
    $isAccountAvatarManager = ($page ?? null) === 'account' && $user?->getKey() === auth()->id();
    $avatars = $isAccountAvatarManager && $profile
        ? ($profile->relationLoaded('avatars') ? $profile->avatars : $profile->avatars()->get())
        : collect();
    $avatarUrl = $activeAvatar?->publicUrl() ?? $placeholderUrl;
    $primaryEmail = $user?->contacts
        ?->first(fn ($contact) => $contact->type === \App\Modules\Contact\Domain\Enums\ContactTypeEnum::EMAIL && $contact->is_primary)
        ?->value;
@endphp

<div class="partial-wrapper partial-avatar text-center" data-tooltip-skip>
    @if(session('avatar_status'))
        <div class="alert alert-success mb-3">{{ session('avatar_status') }}</div>
    @endif

    @if(session('avatar_error') || $errors->has('avatar'))
        <div class="alert alert-danger mb-3">{{ session('avatar_error') ?: $errors->first('avatar') }}</div>
    @endif

    <div class="avatar-upload-block mb-3">
        <div class="avatar-upload-stage">
            <form action="{{ route('account.avatar.store') }}" method="post" enctype="multipart/form-data" class="avatar-upload-form" data-image-upload data-image-upload-auto-submit>
                @csrf
                <label class="avatar-wrapper avatar-upload" for="account-avatar-input" title="Загрузить новый аватар" data-image-upload-surface>
                    @if ($avatarUrl)
                        <img src="{{ $avatarUrl }}" alt="Аватар {{ $displayName }}" class="rounded-circle avatar-lg">
                    @else
                        <span class="avatar-placeholder rounded-circle avatar-lg d-flex align-items-center justify-content-center"></span>
                    @endif
                    <span class="avatar-upload__overlay" aria-hidden="true">
                        <i class="ti ti-camera"></i>
                    </span>
                    @include('theme::partials.image-upload-loading', ['text' => 'Загружаем аватар…'])
                    <span class="visually-hidden">Загрузить новый аватар</span>
                </label>
                <input
                    id="account-avatar-input"
                    class="visually-hidden"
                    type="file"
                    name="avatar"
                    accept="image/jpeg,image/png,image/webp"
                >
            </form>

            @if($isAccountAvatarManager)
                <button
                    type="button"
                    class="avatar-upload__library js-handler"
                    title="Сохранённые аватары: {{ $avatars->count() }}"
                    aria-label="Открыть список аватаров: {{ $avatars->count() }}"
                    data-handler="modal"
                    data-modal-action="open"
                    data-modal-target="account-avatars"
                >{{ $avatars->count() }}</button>
            @endif
        </div>
        <p class="avatar-upload__hint">JPEG, PNG или WebP · до 5 МБ</p>
    </div>

    <h5 class="card-title">{{ $displayName }}</h5>
    <p class="card-text fs-smaller">{{ $primaryEmail }}</p>
</div>

@if($isAccountAvatarManager)
    @component('theme::partials.modal.layout', [
        'id' => 'account-avatars',
        'dialogClass' => 'account-avatar-library__dialog',
    ])
        <h2 class="modal_title" id="modal-title-account-avatars">Аватары</h2>

        <div class="account-avatar-library">
            <div class="account-avatar-library__summary">
                <p>Сохранено {{ $avatars->count() }} из 3. Выберите активный аватар или удалите ненужный.</p>
            </div>

            @if($avatars->isEmpty())
                <p class="account-avatar-library__empty">
                    Сохранённых аватаров пока нет. Нажмите на основной аватар, чтобы загрузить изображение.
                </p>
            @else
                <div class="account-avatar-library__grid">
                    @foreach($avatars as $avatar)
                        <article @class([
                            'account-avatar-library__item',
                            'is-active' => $avatar->is_featured,
                        ])>
                            <div class="account-avatar-library__preview">
                                <img src="{{ $avatar->publicUrl() }}" alt="">
                                @if($avatar->is_featured)
                                    <span class="account-avatar-library__status">Активный</span>
                                @endif
                            </div>

                            <div class="account-avatar-library__actions">
                                @if(!$avatar->is_featured)
                                    <form action="{{ route('account.avatar.activate', $avatar->id) }}" method="post">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn btn--secondary btn--sm">Сделать активным</button>
                                    </form>
                                @else
                                    <span class="account-avatar-library__current">Используется сейчас</span>
                                @endif

                                <form action="{{ route('account.avatar.destroy', $avatar->id) }}" method="post">
                                    @csrf
                                    @method('DELETE')
                                    <button
                                        type="submit"
                                        class="account-avatar-library__delete"
                                        title="Удалить аватар"
                                        aria-label="Удалить аватар"
                                        onclick="return confirm('Вы уверены, что хотите удалить аватар?')"
                                    >
                                        <i class="ti ti-trash" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    @endcomponent
@endif
