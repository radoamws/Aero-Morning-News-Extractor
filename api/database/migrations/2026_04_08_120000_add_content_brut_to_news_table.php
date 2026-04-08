<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('t_news')) {
            return;
        }

        Schema::table('t_news', function (Blueprint $table) {
            if (!Schema::hasColumn('t_news', 'content_brut')) {
                $table->longText('content_brut')->nullable()->after('content');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('t_news')) {
            return;
        }

        if (Schema::hasColumn('t_news', 'content_brut')) {
            Schema::table('t_news', function (Blueprint $table) {
                $table->dropColumn('content_brut');
            });
        }
    }
};
