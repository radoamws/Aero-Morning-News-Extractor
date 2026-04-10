<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_process_logs', function (Blueprint $table) {
            $table->id();
            $table->string('process_type', 50);
            $table->string('status', 20);
            $table->string('source', 20)->nullable();

            $table->unsignedBigInteger('news_id')->nullable();
            $table->string('email_message_id', 255)->nullable();

            $table->text('message')->nullable();
            $table->longText('details')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['process_type', 'status', 'created_at'], 'process_logs_type_status_created_idx');
            $table->index(['news_id'], 'process_logs_news_id_idx');
            $table->index(['email_message_id'], 'process_logs_email_message_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_process_logs');
    }
};
