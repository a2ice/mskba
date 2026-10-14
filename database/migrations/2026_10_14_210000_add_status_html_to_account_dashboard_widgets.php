<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_dashboard_widgets', function (Blueprint $table): void {
            $table->longText('status_confirmed_html')->nullable();
            $table->longText('status_unconfirmed_html')->nullable();
            $table->longText('status_blocked_html')->nullable();
        });

        // Make existing built-in texts immediately editable rather than showing an
        // empty source field while a different string is rendered on the site.
        // Respect any descriptions already authored by an administrator.
        DB::table('account_dashboard_widgets')->where('kind', 'account_status')->update([
            'status_confirmed_html' => '<p>Аккаунт подтверждён. Теперь доступны возможности портала, для которых требуется подтверждение аккаунта.</p>',
            'status_unconfirmed_html' => '<p>Подтверди аккаунт, чтобы получить доступ ко всем возможностям портала.</p>',
            'status_blocked_html' => '<p>Аккаунт заблокирован. Если возникли вопросы, обратись в поддержку.</p>',
        ]);
        DB::table('account_dashboard_widgets')->where('kind', 'getting_started')->whereNull('body')->update([
            'body' => '<p>Добавь роли участия, следи за играми и площадками, общайся с другими участниками MSKBA.</p>',
        ]);
        DB::table('account_dashboard_widgets')->where('kind', 'message_summary')->whereNull('body')->update([
            'body' => '<p>Непрочитанных сообщений и уведомлений: <strong>{{ unread_count }}</strong></p>',
        ]);
    }

    public function down(): void
    {
        Schema::table('account_dashboard_widgets', function (Blueprint $table): void {
            $table->dropColumn(['status_confirmed_html', 'status_unconfirmed_html', 'status_blocked_html']);
        });
    }
};
