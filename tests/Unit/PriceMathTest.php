<?php

namespace Tests\Unit;

use App\Support\PriceMath;
use PHPUnit\Framework\TestCase;

class PriceMathTest extends TestCase
{
    public function test_perubahan_persen(): void
    {
        $this->assertSame(20.0, PriceMath::changePercent(13200.0, 11000.0));
        $this->assertSame(-50.0, PriceMath::changePercent(5000.0, 10000.0));
        $this->assertSame(16.7, PriceMath::changePercent(14000.0, 12000.0));
        $this->assertSame(0.0, PriceMath::changePercent(10000.0, 10000.0));
    }

    public function test_tanpa_pembanding_yang_sah_hasilnya_null(): void
    {
        $this->assertNull(PriceMath::changePercent(10000.0, null));
        $this->assertNull(PriceMath::changePercent(10000.0, 0.0));
        $this->assertNull(PriceMath::changePercent(10000.0, -5.0));
    }

    public function test_dua_hari_terbaru_dengan_satu_baris_per_hari(): void
    {
        $rows = [
            ['day' => '2026-10-18', 'price' => 3],
            ['day' => '2026-10-18 00:00:00', 'price' => 99], // hari sama: diabaikan
            ['day' => '2026-10-17', 'price' => 2],
            ['day' => '2026-10-16', 'price' => 1],
        ];

        [$latest, $previous] = PriceMath::latestTwo($rows);

        $this->assertSame(3, $latest['price']);
        $this->assertSame(2, $previous['price']);
    }

    public function test_satu_hari_atau_tanpa_data(): void
    {
        [$latest, $previous] = PriceMath::latestTwo([['day' => '2026-10-18', 'price' => 3]]);
        $this->assertSame(3, $latest['price']);
        $this->assertNull($previous);

        $this->assertSame([null, null], PriceMath::latestTwo([]));
    }
}
