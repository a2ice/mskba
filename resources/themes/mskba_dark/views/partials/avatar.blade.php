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
    $avatars = $profile
        ? ($profile->relationLoaded('avatars') ? $profile->avatars : $profile->avatars()->get())
        : collect();
    $avatarUrl = $activeAvatar?->publicUrl() ?? $placeholderUrl;
    $primaryEmail = $user?->contacts
        ?->first(fn ($contact) => $contact->type === \App\Modules\Contact\Domain\Enums\ContactTypeEnum::EMAIL && $contact->is_primary)
        ?->value;
@endphp

<div class="partial-wrapper partial-avatar text-center">
    @if(session('avatar_status'))
        <div class="alert alert-success mb-3">{{ session('avatar_status') }}</div>
    @endif

    @if(session('avatar_error') || $errors->has('avatar'))
        <div class="alert alert-danger mb-3">{{ session('avatar_error') ?: $errors->first('avatar') }}</div>
    @endif

    <form action="{{ route('account.avatar.store') }}" method="post" enctype="multipart/form-data" class="avatar-upload-form mb-3">
        @csrf
        <label class="avatar-wrapper avatar-upload" for="account-avatar-input" title="Загрузить новый аватар">
            @if ($avatarUrl)
                <img src="{{ $avatarUrl }}" alt="Аватар {{ $displayName }}" class="rounded-circle avatar-lg">
            @else
                <span class="avatar-placeholder rounded-circle avatar-lg d-flex align-items-center justify-content-center"></span>
            @endif
            <span class="avatar-upload__overlay" aria-hidden="true">
                <i class="ti ti-camera"></i>
            </span>
            <span class="visually-hidden">Загрузить новый аватар</span>
        </label>
        <input
            id="account-avatar-input"
            class="visually-hidden"
            type="file"
            name="avatar"
            accept="image/jpeg,image/png,image/webp"
            onchange="this.form.submit()"
        >
        <p class="avatar-upload__hint">JPEG, PNG или WebP · до 5 МБ</p>
    </form>

    @if($avatars->isNotEmpty())
        <div class="avatar-gallery" aria-label="Сохранённые аватары">
            @foreach($avatars as $avatar)
                <div @class(['avatar-gallery__item', 'is-active' => $avatar->is_featured])>
                    <form action="{{ route('account.avatar.activate', $avatar->id) }}" method="post">
                        @csrf
                        @method('PATCH')
                        <button
                            type="submit"
                            class="avatar-gallery__select"
                            title="Сделать активным"
                            aria-label="Сделать аватар активным"
                            @if($avatar->is_featured) aria-current="true" disabled @endif
                        >
                            <img src="{{ $avatar->publicUrl() }}" alt="">
                        </button>
                    </form>

                    <form action="{{ route('account.avatar.destroy', $avatar->id) }}" method="post">
                        @csrf
                        @method('DELETE')
                        <button
                            type="submit"
                            class="avatar-gallery__delete"
                            title="Удалить аватар"
                            aria-label="Удалить аватар"
                            onclick="return confirm('Вы уверены, что хотите удалить аватар?')"
                        >
                            <i class="ti ti-x" aria-hidden="true"></i>
                        </button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif

    <h5 class="card-title">{{ $displayName }}</h5>
    <p class="card-text fs-smaller">{{ $primaryEmail }}</p>
</div>
