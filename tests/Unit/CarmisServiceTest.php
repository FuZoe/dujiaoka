<?php

namespace Tests\Unit;

use App\Models\Carmis;
use App\Service\CarmisService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CarmisServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('carmis', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('goods_id');
            $table->unsignedTinyInteger('status')->default(Carmis::STATUS_UNSOLD);
            $table->boolean('is_loop')->default(false);
            $table->string('carmi');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_locked_selection_is_stable_and_only_returns_unsold_cards(): void
    {
        $this->insertCard(3, Carmis::STATUS_UNSOLD, false, 'CARD-3');
        $this->insertCard(1, Carmis::STATUS_UNSOLD, false, 'CARD-1');
        $this->insertCard(2, Carmis::STATUS_SOLD, false, 'CARD-2');

        DB::beginTransaction();
        try {
            $cards = (new CarmisService())->withGoodsByAmountAndStatusUnsold(1, 2);
        } finally {
            DB::rollBack();
        }

        $this->assertSame([1, 3], array_column($cards, 'id'));
    }

    public function test_selling_cards_is_conditional_and_reports_the_updated_count(): void
    {
        $this->insertCard(1, Carmis::STATUS_UNSOLD, false, 'ONE-TIME');
        $this->insertCard(2, Carmis::STATUS_SOLD, false, 'ALREADY-SOLD');
        $this->insertCard(3, Carmis::STATUS_UNSOLD, true, 'REUSABLE');
        $service = new CarmisService();

        $this->assertSame(1, $service->soldByIDS([1, 2, 3]));
        $this->assertSame(0, $service->soldByIDS([1, 2, 3]));
        $this->assertSame(Carmis::STATUS_SOLD, (int) Carmis::query()->findOrFail(1)->status);
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::query()->findOrFail(3)->status);
    }

    private function insertCard(int $id, int $status, bool $isLoop, string $carmi): void
    {
        DB::table('carmis')->insert([
            'id' => $id,
            'goods_id' => 1,
            'status' => $status,
            'is_loop' => $isLoop,
            'carmi' => $carmi,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
