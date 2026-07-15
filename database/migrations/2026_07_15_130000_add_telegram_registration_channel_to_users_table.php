<?php

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(sprintf(
            "ALTER TABLE users MODIFY registration_channel ENUM('%s') NULL",
            implode("','", array_column(UserRegistrationChannelEnum::cases(), 'value')),
        ));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('users')
            ->where('registration_channel', UserRegistrationChannelEnum::TELEGRAM_MINI_APP->value)
            ->update(['registration_channel' => UserRegistrationChannelEnum::OTHER->value]);

        DB::statement(sprintf(
            "ALTER TABLE users MODIFY registration_channel ENUM('%s') NULL",
            implode("','", [
                UserRegistrationChannelEnum::SITE_CONTACT_FIRST->value,
                UserRegistrationChannelEnum::SITE_FULL_REGISTRATION->value,
                UserRegistrationChannelEnum::OTHER->value,
                UserRegistrationChannelEnum::SEED->value,
            ]),
        ));
    }
};
