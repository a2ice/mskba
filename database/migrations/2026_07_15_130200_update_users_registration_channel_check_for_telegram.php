<?php

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceConstraint(array_column(UserRegistrationChannelEnum::cases(), 'value'));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::table('users')
            ->where('registration_channel', UserRegistrationChannelEnum::TELEGRAM_MINI_APP->value)
            ->update(['registration_channel' => UserRegistrationChannelEnum::OTHER->value]);

        $this->replaceConstraint([
            UserRegistrationChannelEnum::SITE_CONTACT_FIRST->value,
            UserRegistrationChannelEnum::SITE_FULL_REGISTRATION->value,
            UserRegistrationChannelEnum::OTHER->value,
            UserRegistrationChannelEnum::SEED->value,
        ]);
    }

    /**
     * @param  array<int, string>  $values
     */
    private function replaceConstraint(array $values): void
    {
        $quotedValues = collect($values)
            ->map(fn (string $value): string => DB::getPdo()->quote($value))
            ->implode(', ');

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_registration_channel_check');
        DB::statement(sprintf(
            'ALTER TABLE users ADD CONSTRAINT users_registration_channel_check CHECK (registration_channel IS NULL OR registration_channel IN (%s))',
            $quotedValues,
        ));
    }
};
