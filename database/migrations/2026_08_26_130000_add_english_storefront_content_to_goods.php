<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEnglishStorefrontContentToGoods extends Migration
{
    /**
     * Add optional, merchant-authored English content without altering the
     * existing Chinese storefront fields or historical order data.
     */
    public function up(): void
    {
        if (Schema::hasTable('goods')) {
            $hasName = Schema::hasColumn('goods', 'gd_name_en');
            $hasDescription = Schema::hasColumn('goods', 'gd_description_en');
            $hasKeywords = Schema::hasColumn('goods', 'gd_keywords_en');
            $hasBuyPrompt = Schema::hasColumn('goods', 'buy_prompt_en');
            $hasFullDescription = Schema::hasColumn('goods', 'description_en');
            $hasInputConfig = Schema::hasColumn('goods', 'other_ipu_cnf_en');

            if (!$hasName || !$hasDescription || !$hasKeywords || !$hasBuyPrompt || !$hasFullDescription || !$hasInputConfig) {
                Schema::table('goods', function (Blueprint $table) use ($hasName, $hasDescription, $hasKeywords, $hasBuyPrompt, $hasFullDescription, $hasInputConfig) {
                    if (!$hasName) {
                        $table->string('gd_name_en', 200)->nullable()->after('gd_name');
                    }
                    if (!$hasDescription) {
                        $table->string('gd_description_en', 200)->nullable()->after('gd_description');
                    }
                    if (!$hasKeywords) {
                        $table->string('gd_keywords_en', 200)->nullable()->after('gd_keywords');
                    }
                    if (!$hasBuyPrompt) {
                        $table->text('buy_prompt_en')->nullable()->after('buy_prompt');
                    }
                    if (!$hasFullDescription) {
                        $table->text('description_en')->nullable()->after('description');
                    }
                    if (!$hasInputConfig) {
                        $table->text('other_ipu_cnf_en')->nullable()->after('other_ipu_cnf');
                    }
                });
            }
        }

        if (Schema::hasTable('goods_group') && !Schema::hasColumn('goods_group', 'gp_name_en')) {
            Schema::table('goods_group', function (Blueprint $table) {
                $table->string('gp_name_en', 200)->nullable()->after('gp_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('goods')) {
            $columns = [];
            foreach ([
                'gd_name_en',
                'gd_description_en',
                'gd_keywords_en',
                'buy_prompt_en',
                'description_en',
                'other_ipu_cnf_en',
            ] as $column) {
                if (Schema::hasColumn('goods', $column)) {
                    $columns[] = $column;
                }
            }

            if (!empty($columns)) {
                Schema::table('goods', function (Blueprint $table) use ($columns) {
                    $table->dropColumn($columns);
                });
            }
        }

        if (Schema::hasTable('goods_group') && Schema::hasColumn('goods_group', 'gp_name_en')) {
            Schema::table('goods_group', function (Blueprint $table) {
                $table->dropColumn('gp_name_en');
            });
        }
    }
}
