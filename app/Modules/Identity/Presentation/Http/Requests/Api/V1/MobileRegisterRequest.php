<?php

namespace App\Modules\Identity\Presentation\Http\Requests\Api\V1;

use App\Modules\Identity\Presentation\Http\Requests\RegisterRequest;

final class MobileRegisterRequest extends RegisterRequest
{
    /**
     * Keep all website sign-up rules, normalized username, participation role
     * and required consent; accept a device label instead of web redirects.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['redirect_to']);

        $rules['device_name'] = ['sometimes', 'required', 'string', 'min:1', 'max:80'];

        return $rules;
    }
}
