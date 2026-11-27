<?php

namespace App\Modules\Invitation\Domain\Models;

use App\Modules\Contract\Domain\Models\ContractMembership;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Venue\Domain\Models\Venue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountInvitation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'interested_at' => 'datetime',
            'registered_at' => 'datetime',
            'contact_verified_at' => 'datetime',
            'completed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'views_count' => 'integer',
            'proposed_permissions' => 'array',
        ];
    }

    public function grantedMembership(): BelongsTo
    {
        return $this->belongsTo(ContractMembership::class, 'granted_membership_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AccountInvitationEvent::class, 'invitation_id')->orderBy('id');
    }

    public function targetName(): string
    {
        return match ($this->scope_type) {
            'venue' => Venue::query()->find($this->scope_id)?->name ?? 'Площадка удалена',
            default => 'Объект #'.$this->scope_id,
        };
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function effectiveStatus(): string
    {
        return $this->status === 'pending' && $this->accepted_user_id === null && $this->isExpired()
            ? 'expired' : $this->status;
    }

    public function canManageLink(): bool
    {
        return $this->status === 'pending' && $this->accepted_user_id === null;
    }

    public function canRestoreLink(): bool
    {
        return $this->status === 'revoked' && $this->accepted_user_id === null;
    }

    public function systemStatusLabel(): string
    {
        if ($this->status === 'revoked') {
            return 'Аннулировано';
        }

        $restored = $this->getAttribute('has_restoration');
        if ($restored === null) {
            $restored = $this->relationLoaded('events')
                ? $this->events->contains('event', 'restored')
                : $this->events()->where('event', 'restored')->exists();
        }

        return $restored ? 'Восстановлено' : 'Активно';
    }

    public function invitationStatusLabel(): string
    {
        return match (true) {
            $this->accepted_user_id !== null => 'Принято',
            $this->status === 'revoked' => 'Аннулировано',
            $this->isExpired() => 'Просрочено',
            $this->interested_at !== null => 'Заинтересован',
            $this->viewed_at !== null => 'Просмотрено',
            $this->sent_at !== null => 'Отправлено',
            default => 'Создано',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->effectiveStatus()) {
            'pending' => 'Ожидает завершения',
            'expired' => 'Срок истёк',
            'completed' => 'Завершено',
            'revoked' => 'Отозвано',
            default => 'Неизвестно',
        };
    }

    public function progressLabel(): string
    {
        return match ($this->progress) {
            'created' => 'Создано',
            'sent' => 'Отправлено',
            'viewed' => 'Просмотрено',
            'interested' => 'Заинтересован',
            'registered' => 'Аккаунт создан',
            'contact_verified' => 'Контакт подтверждён',
            'awaiting_account' => 'Ожидается подтверждение аккаунта',
            'awaiting_authorization' => 'Ожидается согласование полномочий',
            'awaiting_ownership' => 'Ожидается проверка владения',
            'completed' => 'Процедура завершена',
            default => '—',
        };
    }
}
