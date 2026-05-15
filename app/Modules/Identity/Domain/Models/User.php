<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['login', 'password', 'is_temp_password', 'status', 'system_role', 'registration_channel'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function contacts(): MorphMany
    {
        return $this->morphMany(Contact::class, 'entity', 'entity_type', 'entity_id');
    }

    public function participationRoles(): HasMany
    {
        return $this->hasMany(UserParticipationRole::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function assignedParticipationRoles(): HasMany
    {
        return $this->hasMany(UserParticipationRole::class, 'assigned_by');
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
            'is_temp_password' => 'boolean',
            'registration_channel' => UserRegistrationChannelEnum::class,
            'system_role' => UserSystemRoleEnum::class,
            'status' => UserStatusEnum::class,
        ];
    }
}
