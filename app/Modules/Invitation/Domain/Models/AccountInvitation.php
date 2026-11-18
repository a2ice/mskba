<?php

namespace App\Modules\Invitation\Domain\Models;

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
            'registered_at' => 'datetime',
            'contact_verified_at' => 'datetime',
            'completed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'views_count' => 'integer',
        ];
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
            'registered' => 'Аккаунт создан',
            'contact_verified' => 'Контакт подтверждён',
            'awaiting_account' => 'Ожидается подтверждение аккаунта',
            'awaiting_ownership' => 'Ожидается проверка владения',
            'completed' => 'Процедура завершена',
            default => '—',
        };
    }
}
