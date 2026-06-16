<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_ignored_emails', function (Blueprint $table) {
            if (!Schema::hasColumn('t_ignored_emails', 'raw_email_json')) {
                $table->longText('raw_email_json')->nullable()->after('excerpt');
            }
            if (!Schema::hasColumn('t_ignored_emails', 'force_published_at')) {
                $table->timestamp('force_published_at')->nullable()->after('raw_email_json');
            }
        });
    }

    public function down(): void
    {
        Schema::table('t_ignored_emails', function (Blueprint $table) {
            $table->dropColumn(['raw_email_json', 'force_published_at']);
        });
    }
};
