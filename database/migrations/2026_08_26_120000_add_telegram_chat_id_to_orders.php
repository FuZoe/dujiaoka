<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTelegramChatIdToOrders extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders') && !Schema::hasColumn('orders', 'telegram_chat_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('telegram_chat_id', 32)->nullable()->after('customer_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'telegram_chat_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('telegram_chat_id');
            });
        }
    }
}
