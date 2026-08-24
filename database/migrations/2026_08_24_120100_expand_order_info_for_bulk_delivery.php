<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class ExpandOrderInfoForBulkDelivery extends Migration
{
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('orders')) {
            return;
        }

        $column = DB::selectOne("SHOW COLUMNS FROM orders LIKE 'info'");
        $type = strtolower((string) ($column->Type ?? ''));
        if ($type === '' || strpos($type, 'mediumtext') === 0 || strpos($type, 'longtext') === 0) {
            return;
        }

        DB::statement('ALTER TABLE orders MODIFY info MEDIUMTEXT NULL');
    }

    public function down(): void
    {
        // Keep the wider column on rollback so existing bulk-delivery data is not truncated.
    }
}
