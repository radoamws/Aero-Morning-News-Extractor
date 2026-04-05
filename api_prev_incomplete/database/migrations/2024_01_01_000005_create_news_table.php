<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_news', function (Blueprint $table) {
            $table->id();
            $table->enum('lang', ['FR', 'EN'])->index();
            $table->text('title');
            $table->longText('content');
            $table->text('metadescription');
            $table->text('focuskeyphrase');
            $table->text('categories')->nullable();
            $table->text('tags')->nullable();
            $table->text('image_url')->nullable();
            $table->tinyInteger('status')->default(0); // 0: pending, 1: syncing, 2: synced
            $table->string('email_message_id')->nullable()->unique();
            $table->timestamps();
            
            $table->index(['status', 'lang']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_news');
    }
};
