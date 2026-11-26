<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TestAccountSequence
{
    public function email(int $number): string
    {
        return 'test_'.$number.'@mskba.ru';
    }

    public function available(ContactTypeEnum $type, string $value): bool
    {
        return ! Contact::withTrashed()->where('type', $type->value)
            ->where(function ($query) use ($type, $value): void {
                $query->where('value', $value);
                if ($type === ContactTypeEnum::EMAIL) {
                    $query->orWhere('user_email_key', $value);
                } elseif ($type === ContactTypeEnum::PHONE) {
                    $query->orWhere('user_phone_key', $value);
                }
            })->exists();
    }

    public function nextNumber(): int
    {
        return $this->findFree((int) DB::table('test_account_counters')->where('id', 1)->value('last_number'));
    }

    /** Must run inside the transaction creating the account and its relations. */
    public function allocate(): int
    {
        $counter = DB::table('test_account_counters')->where('id', 1)->lockForUpdate()->first();
        if ($counter === null) {
            throw new \LogicException('Test account counter is missing.');
        }
        $number = $this->findFree((int) $counter->last_number);
        DB::table('test_account_counters')->where('id', 1)->update(['last_number' => $number]);

        return $number;
    }

    private function findFree(int $last): int
    {
        for ($i = 1; $i <= 10000; $i++) {
            $number = $last + $i;
            if ($this->available(ContactTypeEnum::EMAIL, $this->email($number))) {
                return $number;
            }
        }
        throw ValidationException::withMessages(['test_contact_value' => 'Не удалось подобрать свободный тестовый email.']);
    }
}
