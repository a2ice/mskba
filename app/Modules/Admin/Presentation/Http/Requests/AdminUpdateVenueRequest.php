<?php

namespace App\Modules\Admin\Presentation\Http\Requests;

use App\Modules\Identity\Domain\Enums\UserSystemRoleEnum;
use App\Modules\Venue\Presentation\Http\Requests\UpdateVenueRequest;

final class AdminUpdateVenueRequest extends UpdateVenueRequest
{
    public function authorize(): bool
    {
        return parent::authorize()
            && ($this->user()?->hasSystemRole(UserSystemRoleEnum::SUPERADMIN) ?? false);
    }
}
