<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_news', function (Blueprint $table) {
            $table->unsignedBigInteger('wp_post_id')->nullable()->after('image_url');
            $table->index('wp_post_id');
        });
    }

    public function down(): void
    {
        Schema::table('t_news', function (Blueprint $table) {
            $table->dropIndex(['wp_post_id']);
            $table->dropColumn('wp_post_id');
        });
    }
};
