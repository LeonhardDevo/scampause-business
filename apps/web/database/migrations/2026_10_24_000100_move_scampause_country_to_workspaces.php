<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('workspace_payment_settings', 'country')) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE workspaces SET country = settings.country
            FROM workspace_payment_settings AS settings
            WHERE settings.workspace_id = workspaces.id AND workspaces.country IS NULL
            SQL);

        Schema::table('workspace_payment_settings', function (Blueprint $table): void {
            $table->dropColumn('country');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_payment_settings', function (Blueprint $table): void {
            $table->char('country', 2)->default('US');
        });

        DB::statement(<<<'SQL'
            UPDATE workspace_payment_settings SET country = COALESCE(workspaces.country, CASE WHEN workspaces.locale = 'ru' THEN 'RU' ELSE 'US' END)
            FROM workspaces
            WHERE workspaces.id = workspace_payment_settings.workspace_id
            SQL);
    }
};
