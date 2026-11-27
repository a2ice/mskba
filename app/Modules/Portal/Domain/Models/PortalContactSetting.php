<?php

namespace App\Modules\Portal\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

final class PortalContactSetting extends Model
{
    use Auditable;

    protected $fillable = ['scope', 'email', 'phone', 'email_public', 'phone_public', 'telegram_public', 'vk_public', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['email_public' => 'boolean', 'phone_public' => 'boolean', 'telegram_public' => 'boolean', 'vk_public' => 'boolean'];
    }
}
