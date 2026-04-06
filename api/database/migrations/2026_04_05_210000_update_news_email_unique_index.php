<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_news', function (Blueprint $table) {
            $table->dropUnique('t_news_email_message_id_unique');
            $table->unique(['email_message_id', 'lang'], 't_news_email_message_lang_unique');
        });
    }

    public function down(): void
    {
        Schema::table('t_news', function (Blueprint $table) {
            $table->dropUnique('t_news_email_message_lang_unique');
            $table->unique('email_message_id', 't_news_email_message_id_unique');
        });
    }
};
