<?php

namespace App\Modules\Contact\Application\UseCases;

use App\Modules\Contact\Application\Services\ContactVerificationStrategyResolver;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Domain\Models\ContactVerification;
use LogicException;

class StartContactVerificationHandler
{
    public function __construct(
        private readonly ContactVerificationStrategyResolver $strategyResolver,
    ) {}

    public function handle(Contact $contact): ContactVerification
    {
        if ($contact->hasBeenVerified()) {
            throw new LogicException('Контакт уже подтвержден.');
        }

        return $this->strategyResolver
            ->resolve($contact)
            ->start($contact);
    }
}
