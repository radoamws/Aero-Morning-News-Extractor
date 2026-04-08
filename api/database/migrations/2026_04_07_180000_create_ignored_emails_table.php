<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_ignored_emails', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->nullable()->index();
            $table->text('subject')->nullable();
            $table->text('sender')->nullable();
            $table->string('reason')->default('not_relevant');
            $table->longText('excerpt')->nullable();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['reason', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_ignored_emails');
    }
};