<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTelegramOrderNotificationsTable extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('telegram_order_notifications')) {
            Schema::create('telegram_order_notifications', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('order_id')->index();
                $table->string('event_key', 64);
                $table->string('status', 20)->default('queued');
                $table->unsignedInteger('next_part')->default(0);
                $table->string('last_error', 190)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->unique(['order_id', 'event_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_order_notifications');
    }
}
