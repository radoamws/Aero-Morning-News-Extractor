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

        try {
            Schema::table('t_news', function (Blueprint $table) {
                $table->dropUnique('t_news_email_message_id_unique');
            });
        } catch (\Throwable $e) {
            // Ignore if the index does not exist (e.g. after manual DB changes)
        }

        try {
            Schema::table('t_news', function (Blueprint $table) {
                $table->unique(['email_message_id', 'lang'], 't_news_email_message_lang_unique');
            });
        } catch (\Throwable $e) {
            // Ignore if the index already exists
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('t_news')) {
            return;
        }

        try {
            Schema::table('t_news', function (Blueprint $table) {
                $table->dropUnique('t_news_email_message_lang_unique');
            });
        } catch (\Throwable $e) {
            // Ignore if the index does not exist
        }

        try {
            Schema::table('t_news', function (Blueprint $table) {
                $table->unique('email_message_id', 't_news_email_message_id_unique');
            });
        } catch (\Throwable $e) {
            // Ignore if the index already exists
        }
    }
};
