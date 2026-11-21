<?php

namespace App\Modules\Contact\Application\UseCases;

use App\Modules\Contact\Application\Services\ContactVerificationStrategyResolver;
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
            $locked = Contact::query()->whereKey($contact->id)->lockForUpdate()->firstOrFail();
            if ($locked->hasBeenVerified()) {
                throw new LogicException('Контакт уже подтвержден.');
            }

            return $this->strategyResolver->resolve($locked)->start($locked);
        });
    }
}
