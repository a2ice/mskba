<?php

namespace App\Modules\Identity\Domain\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\Audit\Domain\Traits\Auditable;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Enums\UserParticipationRoleStatusEnum;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Exceptions\UserCannotBeChangedException;
use App\Modules\Identity\Domain\Exceptions\UserProfileAlreadyExistsException;
use App\Modules\Identity\Domain\Models\Participation\PlayerProfile;
use App\Modules\Identity\Infrastructure\Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['username', 'password', 'password_updated_at', 'is_temporary_password', 'first_logged_in_at', 'status', 'system_role', 'registration_channel'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable;

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_temporary_password' => 'boolean',
            'password_updated_at' => 'datetime',
            'first_logged_in_at' => 'datetime',
            'registration_channel' => UserRegistrationChannelEnum::class,
            'system_role' => UserSystemRoleEnum::class,
            'status' => UserStatusEnum::class,
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function playerProfile(): HasOne
    {
        return $this->hasOne(PlayerProfile::class);
    }

    public function createProfile(array $data): Profile
    {
        if ($this->profile()->exists()) {
            throw new UserProfileAlreadyExistsException;
        }

        if ($this->isBlocked()) {
            throw new UserCannotBeChangedException;
        }

        return $this->profile()->create($data);
    }

    public function participationRoles(bool $isActive = true): HasMany
    {
        return $this->hasMany(UserParticipationRole::class)
            ->when($isActive, function ($query) {
                $query->where('status', UserParticipationRoleStatusEnum::ACTIVE);
            });
    }

    public function contacts(): MorphMany
    {
        return $this->morphMany(Contact::class, 'contactable');
    }

    public function fingerprints(): BelongsToMany
    {
        return $this
            ->belongsToMany(UserFingerprint::class, 'user_fingerprint_user')
            ->withPivot([
                'authentications_count',
                'first_authenticated_at',
                'last_authenticated_at',
            ])
            ->withTimestamps();
    }

    public function primaryEmail(): ?Contact
    {
        $contact = $this->contacts()
            ->where('type', 'email')
            ->where('is_primary', true)
            ->first(); // dd($contact);

        return $contact;
    }

    public function hasVerifiedPrimaryContact(): bool
    {
        return $this->contacts()
            ->where('is_primary', true)
            ->whereNotNull('verified_at')
            ->exists();
    }

    /**
     * @throws UserCannotBeChangedException
     */
    public function confirmAccount(): void
    {
        if ($this->status !== UserStatusEnum::UNCONFIRMED) {
            throw new UserCannotBeChangedException('Подтвердить можно только неподтвержденный аккаунт.');
        }

        if (! $this->hasVerifiedPrimaryContact()) {
            throw new UserCannotBeChangedException('Для подтверждения аккаунта нужен подтвержденный основной контакт.');
        }

        $this->forceFill([
            'status' => UserStatusEnum::CONFIRMED,
        ])->save();
    }

    /**
     * @throws UserCannotBeChangedException
     */
    public function blockAccount(): void
    {
        if ($this->status === UserStatusEnum::REMOVED) {
            throw new UserCannotBeChangedException('Удаленный аккаунт нельзя заблокировать.');
        }

        if ($this->status === UserStatusEnum::BLOCKED) {
            return;
        }

        $this->forceFill([
            'status' => UserStatusEnum::BLOCKED,
        ])->save();
    }

    public function hasSystemRole(UserSystemRoleEnum|string $role): bool
    {
        return $this->system_role === $role;
    }

    public function isAdmin(bool $isConfirmed = true): bool
    {
        $numericRoleValue = $this->system_role->numericValue();

        return $numericRoleValue >= UserSystemRoleEnum::ADMIN->numericValue() && (! $isConfirmed || $this->isConfirmed());
    }

    public function hasRole(string $role, bool $isActive = true): bool
    {
        return $this->participationRoles()
            ->where('role', $role)
            ->when($isActive, function ($query) {
                $query->where('status', UserParticipationRoleStatusEnum::ACTIVE);
            })
            ->exists();
    }

    public function hasActiveRole(string $role): bool
    {
        return $this->participationRoles()->where('role', $role)->where('status', UserParticipationRoleStatusEnum::ACTIVE)->exists();
    }

    public function isConfirmed(): bool
    {
        return $this->status === UserStatusEnum::CONFIRMED;
    }

    public function isBlocked(): bool
    {
        return $this->status === UserStatusEnum::BLOCKED;
    }
}
