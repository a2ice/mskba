<?php

namespace App\Modules\Identity\Application\UseCases;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Presentation\Mail\TemporaryPasswordMail;
use App\Modules\Telegram\Application\Services\TelegramNotificationRecipientResolver;
use App\Modules\Telegram\Infrastructure\Services\TelegramBotApiClient;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

final class RecoverUserPasswordHandler
{
    public function __construct(
        private readonly SetUserPasswordHandler $passwords,
        private readonly TelegramNotificationRecipientResolver $telegramRecipients,
        private readonly TelegramBotApiClient $telegram,
    ) {}

    /**
     * A contact value (not an account username) is the selected destination.
     * Returns silently for unknown, unverified, ambiguous or undeliverable
     * identities: the public endpoint must never enumerate accounts.
     */
    public function handle(string $input): void
    {
        $input = trim($input);
        $email = filter_var($input, FILTER_VALIDATE_EMAIL) !== false;
        $telegramUsername = preg_match('/^@[A-Za-z0-9_]{5,32}$/D', $input) === 1;

        if (! $email && ! $telegramUsername) {
            return;
        }

        $contacts = Contact::query()
            ->where('contactable_type', 'user')
            ->where('type', $email ? ContactTypeEnum::EMAIL->value : ContactTypeEnum::TELEGRAM->value)
            ->whereNotNull('verified_at')
            ->when($email,
                fn ($query) => $query->where('value', mb_strtolower($input)),
                fn ($query) => $query->where('meta->username', ltrim(mb_strtolower($input), '@')),
            )
            ->get();

        if ($contacts->isEmpty()) {
            return;
        }

        $users = User::query()->whereIn('id', $contacts->pluck('contactable_id')->all())->get();
        if ($users->count() !== $contacts->pluck('contactable_id')->unique()->count()) {
            return;
        }

        $canonicalIds = $users->map(fn (User $user): int => (int) $user->canonical()->id)->unique();
        if ($canonicalIds->count() !== 1) {
            return;
        }

        $user = $users->first()->canonical();
        if ($user->isBlocked() || $user->trashed()) {
            return;
        }

        /** @var Contact $contact */
        $contact = $contacts->first();
        $telegramAccount = null;

        if ($email) {
            if (! $this->mailerCanDeliver((string) config('mail.default', 'log'))) {
                return;
            }
        } else {
            $telegramAccount = $this->telegramRecipients->resolve($user);

            if ($telegramAccount === null
                || (string) $telegramAccount->telegram_user_id !== (string) $contact->value
                || ! $this->telegram->isBotConfigured()) {
                return;
            }
        }

        // Cryptographically random, always meeting the shared PasswordVO rules.
        $password = bin2hex(random_bytes(14)).'Aa1!';

        $this->passwords->handleRecovery($user, $password, function () use ($contact, $email, $password, $telegramAccount): void {
            // Revalidate the exact selected contact inside the credential
            // transaction, not merely the account's primary contact.
            if (! Contact::query()->whereKey($contact->id)->whereNotNull('verified_at')->exists()) {
                throw new RuntimeException('Recovery contact is no longer verified.');
            }

            if ($email) {
                Mail::to($contact->value)->send(new TemporaryPasswordMail($password));

                return;
            }

            $this->telegram->call('sendMessage', [
                'chat_id' => $telegramAccount->private_chat_id,
                'text' => "MSKBA: новый временный пароль для входа:\n".$password."\nПосле входа установите собственный пароль в настройках аккаунта.",
            ]);
        });
    }

    private function mailerCanDeliver(string $mailer): bool
    {
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);

        if (in_array($transport, ['log', 'array', 'null'], true)) {
            return false;
        }

        if ($transport === 'failover' || $transport === 'roundrobin') {
            $mailers = (array) config("mail.mailers.{$mailer}.mailers", []);

            return $mailers !== [] && collect($mailers)->every(
                fn ($candidate): bool => $this->mailerCanDeliver((string) $candidate),
            );
        }

        return true;
    }
}
