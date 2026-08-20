<?php

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceRegistrationChannels(array_column(UserRegistrationChannelEnum::cases(), 'value'));
    }

    public function down(): void
    {
        DB::table('users')
            ->where('registration_channel', UserRegistrationChannelEnum::VK_ID->value)
            ->update(['registration_channel' => UserRegistrationChannelEnum::OTHER->value]);

        $channels = collect(UserRegistrationChannelEnum::cases())
            ->reject(fn (UserRegistrationChannelEnum $channel): bool => $channel === UserRegistrationChannelEnum::VK_ID)
            ->map(fn (UserRegistrationChannelEnum $channel): string => $channel->value)
            ->all();

        $this->replaceRegistrationChannels($channels);
    }

    /** @param list<string> $values */
    private function replaceRegistrationChannels(array $values): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $quoted = collect($values)
                ->map(fn (string $value): string => DB::getPdo()->quote($value))
                ->implode(', ');

            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_registration_channel_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_registration_channel_check CHECK (registration_channel IS NULL OR registration_channel IN ({$quoted}))");
        } elseif (DB::getDriverName() === 'mysql') {
            $quoted = collect($values)
                ->map(fn (string $value): string => str_replace("'", "''", $value))
                ->implode("','");

            DB::statement("ALTER TABLE users MODIFY registration_channel ENUM('{$quoted}') NULL");
        }
    }
};
