<?php

namespace App\Modules\Contact\Application\Services;

use App\Modules\Contact\Application\Contracts\ContactVerificationStrategy;
use App\Modules\Contact\Domain\Models\Contact;
use LogicException;

class ContactVerificationStrategyResolver
{
    /**
     * @param  iterable<ContactVerificationStrategy>  $strategies
     */
    public function __construct(
        private readonly iterable $strategies,
    ) {}

    public function resolve(Contact $contact): ContactVerificationStrategy
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($contact)) {
                return $strategy;
            }
        }

        throw new LogicException('Для этого типа контакта подтверждение пока не реализовано.');
    }
}
