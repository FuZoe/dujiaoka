<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTelegramBindingsTable extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('telegram_bindings')) {
            Schema::create('telegram_bindings', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('customer_id')->index();
                $table->char('token_hash', 64)->unique();
                $table->timestamp('expires_at');
                $table->timestamp('consumed_at')->nullable();
                $table->string('failure_reason', 64)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_bindings');
    }
}
