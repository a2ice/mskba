<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Contact\Domain\Enums\ContactStatusEnum;
use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\ContactVerification\Application\Services\ContactVerificationManager;
use App\Modules\ContactVerification\Domain\Enums\ContactVerificationPurposeEnum;
use App\Modules\Identity\Application\Services\TemporaryPasswordMailer;
use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use App\Modules\Identity\Domain\Enums\UserStatusEnum;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\ValueObjects\PasswordVO;
use Illuminate\Support\Facades\DB;

class RegisterContactFirstHandler
{
    public function __construct(
        private readonly ContactVerificationManager $contactVerificationManager,
        private readonly TemporaryPasswordMailer $temporaryPasswordMailer,
    ) {
    }

    /**
     * @return array{status:string,message:string,httpStatus:int,login?:string,next?:string}
     */
    public function handle(string $email): array
    {
        $normalizedEmail = mb_strtolower(trim($email));

        if ($this->emailContactExists($normalizedEmail)) {
            return [
                'status' => 'contact_already_exists',
                'message' => 'Этот email уже используется.',
                'httpStatus' => 422,
            ];
        }

        $temporaryPassword = PasswordVO::generate();

        DB::transaction(function () use ($normalizedEmail, $temporaryPassword): void {
            $user = User::query()->create([
                'password' => (string) $temporaryPassword,
                'is_temp_password' => true,
                'registration_channel' => UserRegistrationChannelEnum::SITE_CONTACT_FIRST,
                'status' => UserStatusEnum::UNCONFIRMED,
            ]);

            $contact = Contact::query()->create([
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'contact_type' => ContactTypeEnum::EMAIL,
                'value' => $normalizedEmail,
                'status' => ContactStatusEnum::UNVERIFIED,
            ]);

            $this->contactVerificationManager->startForContact(
                $contact,
                ContactVerificationPurposeEnum::SITE_CONTACT_FIRST,
                (string) $temporaryPassword,
            );
        });

        $this->temporaryPasswordMailer->send($normalizedEmail, (string) $temporaryPassword);

        return [
            'status' => 'temp_password_sent',
            'message' => 'Мы отправили временный пароль на email. Введите его для входа.',
            'httpStatus' => 200,
            'login' => $normalizedEmail,
            'next' => 'login',
        ];
    }

    private function emailContactExists(string $normalizedEmail): bool
    {
        return Contact::query()
            ->where('entity_type', 'user')
            ->where('contact_type', ContactTypeEnum::EMAIL->value)
            ->whereRaw('LOWER(value) = ?', [$normalizedEmail])
            ->exists();
    }
}
