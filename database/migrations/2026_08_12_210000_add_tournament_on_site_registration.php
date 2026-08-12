<?php

use App\Modules\Identity\Domain\Enums\UserRegistrationChannelEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', fn (Blueprint $table) => $table->boolean('allows_on_site_registration')->default(false));
        Schema::table('tournament_admissions', function (Blueprint $table): void {
            $table->string('source', 32)->default('standard');
            $table->index(['tournament_id', 'source', 'status']);
        });
        $this->replaceRegistrationChannels(array_column(UserRegistrationChannelEnum::cases(), 'value'));
    }

    public function down(): void
    {
        DB::table('users')->where('registration_channel', UserRegistrationChannelEnum::TOURNAMENT_ON_SITE->value)->update(['registration_channel' => UserRegistrationChannelEnum::OTHER->value]);
        $channels = collect(UserRegistrationChannelEnum::cases())->reject(fn ($channel) => $channel === UserRegistrationChannelEnum::TOURNAMENT_ON_SITE)->map->value->all();
        $this->replaceRegistrationChannels($channels);
        Schema::table('tournament_admissions', function (Blueprint $table): void {
            $table->dropIndex(['tournament_id', 'source', 'status']);
            $table->dropColumn('source');
        });
        Schema::table('tournaments', fn (Blueprint $table) => $table->dropColumn('allows_on_site_registration'));
    }

    private function replaceRegistrationChannels(array $values): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $quoted = collect($values)->map(fn (string $value): string => DB::getPdo()->quote($value))->implode(', ');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_registration_channel_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_registration_channel_check CHECK (registration_channel IS NULL OR registration_channel IN ({$quoted}))");
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY registration_channel ENUM('".implode("','", $values)."') NULL");
        }
    }
};
