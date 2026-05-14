<?php

namespace App\Modules\Identity\Application\Services;

use Illuminate\Support\Facades\Mail;

class TemporaryPasswordMailer
{
    public function send(string $email, string $temporaryPassword): void
    {
        Mail::raw(
            "Ваш временный пароль: {$temporaryPassword}\n\nВведите его на сайте для входа в аккаунт.",
            function ($message) use ($email): void {
                $message
                    ->to($email)
                    ->subject('Временный пароль для входа');
            },
        );
    }
}
