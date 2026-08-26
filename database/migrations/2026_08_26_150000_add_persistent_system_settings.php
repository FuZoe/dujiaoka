<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPersistentSystemSettings extends Migration
{
    public function up()
    {
        if (Schema::hasTable('newzoe_system_settings')) {
            return;
        }

        Schema::create('newzoe_system_settings', function (Blueprint $table) {
            $table->string('setting_key', 100)->primary();
            $table->longText('setting_value');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('newzoe_system_settings');
    }
}
