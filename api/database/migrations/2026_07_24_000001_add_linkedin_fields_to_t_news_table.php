<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_news', function (Blueprint $table) {
            // true si le mail source mentionne "linkedin" → à publier automatiquement
            $table->boolean('linkedin')->default(false)->after('wp_post_id');
            // true une fois le webhook Make déclenché avec succès après le publish WP
            $table->boolean('linkedin_posted')->default(false)->after('linkedin');
        });
    }

    public function down(): void
    {
        Schema::table('t_news', function (Blueprint $table) {
            $table->dropColumn(['linkedin', 'linkedin_posted']);
        });
    }
};
