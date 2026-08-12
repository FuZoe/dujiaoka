<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomersTable extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('email', 190)->unique();
                $table->string('password');
                $table->string('telegram_chat_id', 32)->nullable()->unique();
                $table->string('telegram_username', 64)->nullable();
                $table->string('telegram_name', 190)->nullable();
                $table->timestamp('telegram_bound_at')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('orders') && !Schema::hasColumn('orders', 'customer_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'customer_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('customer_id');
            });
        }
        Schema::dropIfExists('customers');
    }
}
