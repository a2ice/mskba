<?php

use App\Modules\Notification\Domain\Enums\UserNotificationTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateAllowedTypes(UserNotificationTypeEnum::cases());
    }

    public function down(): void
    {
        if (DB::table('user_notifications')->where('type', UserNotificationTypeEnum::MESSAGE->value)->exists()) {
            throw new RuntimeException('Нельзя удалить тип message, пока существуют уведомления этого типа.');
        }

        $this->updateAllowedTypes(array_filter(
            UserNotificationTypeEnum::cases(),
            static fn (UserNotificationTypeEnum $type): bool => $type !== UserNotificationTypeEnum::MESSAGE,
        ));
    }

    /** @param array<int, UserNotificationTypeEnum> $types */
    private function updateAllowedTypes(array $types): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['pgsql', 'mysql'], true)) {
            return;
        }

        $quoted = collect($types)
            ->map(fn (UserNotificationTypeEnum $type): string => DB::getPdo()->quote($type->value))
            ->implode(', ');

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE user_notifications DROP CONSTRAINT IF EXISTS user_notifications_type_check');
            DB::statement("ALTER TABLE user_notifications ADD CONSTRAINT user_notifications_type_check CHECK (type IN ({$quoted}))");

            return;
        }

        DB::statement("ALTER TABLE user_notifications MODIFY type ENUM({$quoted}) NOT NULL");
    }
};
