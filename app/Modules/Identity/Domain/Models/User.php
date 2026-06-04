<?php

namespace App\Modules\Identity\Domain\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\Contact\Domain\Models\Contact;
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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['username', 'password', 'password_updated_at', 'is_temporary_password', 'status', 'system_role', 'registration_channel'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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

    public function participationRoles(): HasMany
    {
        return $this->hasMany(UserParticipationRole::class);
    }

    public function contacts(): MorphMany
    {
        return $this->morphMany(Contact::class, 'contactable');
    }

    public function primaryEmail(): ?Contact
    {
        $contact = $this->contacts()
            ->where('type', 'email')
            ->where('is_primary', true)
            ->first();//dd($contact);

        return $contact;
    }

    public function hasSystemRole(UserSystemRoleEnum|string $role): bool
    {
        return $this->system_role === $role;
    }

    public function isAdmin(bool $isConfirmed = true): bool
    {
        $numericRoleValue = $this->system_role->numericValue();
        
        return $numericRoleValue >= UserSystemRoleEnum::ADMIN->numericValue() && (!$isConfirmed || $this->isConfirmed());
    }

    public function hasRole(string $role): bool
    {
        return $this->participationRoles()->where('role', $role)->exists();
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
