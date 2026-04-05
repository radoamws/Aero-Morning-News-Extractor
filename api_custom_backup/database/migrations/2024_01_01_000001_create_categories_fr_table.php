<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_categories_fr', function (Blueprint $table) {
            $table->id();
            $table->integer('wp_id')->unique();
            $table->string('categ_name')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_categories_fr');
    }
};
