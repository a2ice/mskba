<?php

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceConstraint(array_column(UserRegistrationChannelEnum::cases(), 'value'));
    }

    public function down(): void
    {
        DB::table('users')
            ->where('registration_channel', UserRegistrationChannelEnum::TELEGRAM_CHAT->value)
            ->update(['registration_channel' => UserRegistrationChannelEnum::OTHER->value]);

        $values = collect(UserRegistrationChannelEnum::cases())
            ->reject(fn (UserRegistrationChannelEnum $channel): bool => $channel === UserRegistrationChannelEnum::TELEGRAM_CHAT)
            ->map(fn (UserRegistrationChannelEnum $channel): string => $channel->value)
            ->all();

        $this->replaceConstraint($values);
    }

    /**
     * @param  list<string>  $values
     */
    private function replaceConstraint(array $values): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $quotedValues = collect($values)
                ->map(fn (string $value): string => DB::getPdo()->quote($value))
                ->implode(', ');

            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_registration_channel_check');
            DB::statement(sprintf(
                'ALTER TABLE users ADD CONSTRAINT users_registration_channel_check CHECK (registration_channel IS NULL OR registration_channel IN (%s))',
                $quotedValues,
            ));
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                "ALTER TABLE users MODIFY registration_channel ENUM('%s') NULL",
                implode("','", $values),
            ));
        }
    }
};
