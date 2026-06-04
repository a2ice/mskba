<?php

namespace App\Modules\Contact\Application\Contracts;

use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Domain\Models\ContactVerification;

interface ContactVerificationStrategy
{
    public function supports(Contact $contact): bool;

    public function start(Contact $contact): ContactVerification;
}
