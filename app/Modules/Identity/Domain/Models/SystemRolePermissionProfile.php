<?php

namespace App\Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['role', 'permissions'])]
final class SystemRolePermissionProfile extends Model
{
    protected $primaryKey = 'role';
    public $incrementing = false;
    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }
}