<?php

namespace App\Modules\Contact\Application\UseCases;

use App\Modules\Contact\Application\Services\ContactVerificationStrategyResolver;
use App\Modules\Contact\Application\Services\ContactOwnership;
use App\Modules\Identity\Domain\Models\User;
use InvalidArgumentException;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Domain\Models\ContactVerification;
use Illuminate\Support\Facades\DB;
use LogicException;

class StartContactVerificationHandler
{
    public function __construct(
        private readonly ContactVerificationStrategyResolver $strategyResolver,
    ) {}

    public function handle(Contact $contact): ContactVerification
    {
        return DB::transaction(function () use ($contact): ContactVerification {
            $ownership = app(ContactOwnership::class);
            if ($contact->contactable_type === 'user') {
                $ownership->lock($contact->type, $contact->value);
            }
            $locked = Contact::query()->withTrashed()->whereKey($contact->id)->lockForUpdate()->firstOrFail();
            if ($locked->trashed()) {
                throw new LogicException('Контакт больше недоступен. Добавьте новый контакт.');
            }
            if ($locked->contactable_type === 'user') {
                $user = User::query()->findOrFail($locked->contactable_id)->canonical();
                try {
                    $ownership->assertAvailable($locked->type, $locked->value, $user->identityIds());
                } catch (InvalidArgumentException $exception) {
                    throw new LogicException($exception->getMessage(), previous: $exception);
                }
            }
            if ($locked->hasBeenVerified()) {
                throw new LogicException('Контакт уже подтвержден.');
            }

            return $this->strategyResolver->resolve($locked)->start($locked);
        });
    }
}
