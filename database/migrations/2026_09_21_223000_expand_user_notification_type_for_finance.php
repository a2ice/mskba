<?php

use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $values = array_map(
            static fn (UserNotificationTypeEnum $type): string => $type->value,
            UserNotificationTypeEnum::cases(),
        );

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $quoted = collect($values)
                ->map(fn (string $value): string => DB::getPdo()->quote($value))
                ->implode(', ');

            DB::statement('ALTER TABLE user_notifications DROP CONSTRAINT IF EXISTS user_notifications_type_check');
            DB::statement(
                "ALTER TABLE user_notifications ADD CONSTRAINT user_notifications_type_check CHECK (type IN ({$quoted}))",
            );

            return;
        }

        if ($driver === 'mysql') {
            $quoted = collect($values)
                ->map(fn (string $value): string => DB::getPdo()->quote($value))
                ->implode(', ');

            DB::statement("ALTER TABLE user_notifications MODIFY type ENUM({$quoted}) NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::table('user_notifications')->where('type', UserNotificationTypeEnum::FINANCE->value)->exists()) {
            throw new RuntimeException('Нельзя удалить тип finance, пока существуют финансовые уведомления.');
        }

        $values = collect(UserNotificationTypeEnum::cases())
            ->reject(fn (UserNotificationTypeEnum $type): bool => $type === UserNotificationTypeEnum::FINANCE)
            ->map(fn (UserNotificationTypeEnum $type): string => $type->value)
            ->values()
            ->all();

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $quoted = collect($values)
                ->map(fn (string $value): string => DB::getPdo()->quote($value))
                ->implode(', ');

            DB::statement('ALTER TABLE user_notifications DROP CONSTRAINT IF EXISTS user_notifications_type_check');
            DB::statement(
                "ALTER TABLE user_notifications ADD CONSTRAINT user_notifications_type_check CHECK (type IN ({$quoted}))",
            );

            return;
        }

        if ($driver === 'mysql') {
            $quoted = collect($values)
                ->map(fn (string $value): string => DB::getPdo()->quote($value))
                ->implode(', ');

            DB::statement("ALTER TABLE user_notifications MODIFY type ENUM({$quoted}) NOT NULL");
        }
    }
};
