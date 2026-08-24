<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddManualFulfillmentFieldsToOrders extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        $hasFulfilledAt = Schema::hasColumn('orders', 'manual_fulfilled_at');
        $hasFulfilledBy = Schema::hasColumn('orders', 'manual_fulfilled_by');
        if ($hasFulfilledAt && $hasFulfilledBy) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) use ($hasFulfilledAt, $hasFulfilledBy) {
            if (!$hasFulfilledAt) {
                $table->timestamp('manual_fulfilled_at')->nullable()->after('trade_no');
            }
            if (!$hasFulfilledBy) {
                $table->string('manual_fulfilled_by', 64)->nullable()->after('manual_fulfilled_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        $hasFulfilledAt = Schema::hasColumn('orders', 'manual_fulfilled_at');
        $hasFulfilledBy = Schema::hasColumn('orders', 'manual_fulfilled_by');
        if (!$hasFulfilledAt && !$hasFulfilledBy) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) use ($hasFulfilledAt, $hasFulfilledBy) {
            if ($hasFulfilledBy) {
                $table->dropColumn('manual_fulfilled_by');
            }
            if ($hasFulfilledAt) {
                $table->dropColumn('manual_fulfilled_at');
            }
        });
    }
}
