<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WarzoneSupplierAdminMenuTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('admin_permission_menu');
        Schema::dropIfExists('admin_role_menu');
        Schema::dropIfExists('admin_menu');
        Schema::create('admin_menu', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('parent_id')->default(0);
            $table->integer('order')->default(0);
            $table->string('title', 50);
            $table->string('icon', 50)->nullable();
            $table->string('uri', 50)->nullable();
            $table->string('extension', 50)->default('');
            $table->boolean('show')->default(true);
            $table->timestamps();
        });
        Schema::create('admin_role_menu', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('menu_id');
            $table->unique(['role_id', 'menu_id']);
        });
        Schema::create('admin_permission_menu', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('menu_id');
            $table->unique(['permission_id', 'menu_id']);
        });
        DB::table('admin_menu')->insert([
            ['id' => 11, 'parent_id' => 0, 'order' => 9, 'title' => 'Goods', 'uri' => null],
            ['id' => 12, 'parent_id' => 11, 'order' => 11, 'title' => 'Goods list', 'uri' => '/goods'],
        ]);
        DB::table('admin_role_menu')->insert(['role_id' => 3, 'menu_id' => 12]);
        DB::table('admin_permission_menu')->insert(['permission_id' => 5, 'menu_id' => 12]);
    }

    public function test_menu_uses_relative_uri_and_copies_goods_access_bindings(): void
    {
        $migration = $this->migration();
        $migration->up();

        $menu = DB::table('admin_menu')->where('uri', 'warzone-supplier')->first();
        $this->assertNotNull($menu);
        $this->assertSame(11, (int) $menu->parent_id);
        $this->assertFalse(DB::table('admin_menu')->where('uri', '/warzone-supplier')->exists());
        $this->assertTrue(DB::table('admin_role_menu')
            ->where(['role_id' => 3, 'menu_id' => $menu->id])->exists());
        $this->assertTrue(DB::table('admin_permission_menu')
            ->where(['permission_id' => 5, 'menu_id' => $menu->id])->exists());

        $migration->down();
        $this->assertFalse(DB::table('admin_menu')
            ->whereIn('uri', ['warzone-supplier', '/warzone-supplier'])->exists());
    }

    private function migration()
    {
        require_once database_path('migrations/2026_09_01_010100_add_warzone_supplier_admin_menu.php');

        return new \AddWarzoneSupplierAdminMenu();
    }
}
