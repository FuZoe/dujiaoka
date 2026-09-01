<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddWarzoneSupplierAdminMenu extends Migration
{
    private const URI = 'warzone-supplier';

    private const LEGACY_URI = '/warzone-supplier';

    public function up(): void
    {
        if (!Schema::hasTable('admin_menu')
            || DB::table('admin_menu')->whereIn('uri', [self::URI, self::LEGACY_URI])->exists()) {
            return;
        }

        $goodsMenu = DB::table('admin_menu')
            ->whereIn('uri', ['/goods', 'goods'])
            ->orderBy('id')
            ->first();
        $parentId = $goodsMenu ? (int) $goodsMenu->parent_id : 0;
        $order = (int) DB::table('admin_menu')->where('parent_id', $parentId)->max('order') + 1;
        $menuId = DB::table('admin_menu')->insertGetId([
            'parent_id' => $parentId,
            'order' => $order,
            'title' => '供应商配置',
            'icon' => 'fa-truck',
            'uri' => self::URI,
            'extension' => '',
            'show' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (!$goodsMenu) {
            return;
        }

        $this->copyBindings('admin_role_menu', 'role_id', (int) $goodsMenu->id, $menuId);
        $this->copyBindings('admin_permission_menu', 'permission_id', (int) $goodsMenu->id, $menuId);
    }

    public function down(): void
    {
        if (!Schema::hasTable('admin_menu')) {
            return;
        }

        $menuIds = DB::table('admin_menu')
            ->whereIn('uri', [self::URI, self::LEGACY_URI])
            ->pluck('id')
            ->all();
        if (!$menuIds) {
            return;
        }
        if (Schema::hasTable('admin_role_menu')) {
            DB::table('admin_role_menu')->whereIn('menu_id', $menuIds)->delete();
        }
        if (Schema::hasTable('admin_permission_menu')) {
            DB::table('admin_permission_menu')->whereIn('menu_id', $menuIds)->delete();
        }
        DB::table('admin_menu')->whereIn('id', $menuIds)->delete();
    }

    private function copyBindings(string $table, string $ownerColumn, int $sourceMenuId, int $targetMenuId): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        $rows = DB::table($table)->where('menu_id', $sourceMenuId)->get([$ownerColumn]);
        foreach ($rows as $row) {
            DB::table($table)->insertOrIgnore([
                $ownerColumn => $row->{$ownerColumn},
                'menu_id' => $targetMenuId,
            ]);
        }
    }
}
