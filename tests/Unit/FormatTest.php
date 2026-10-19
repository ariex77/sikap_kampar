<?php

namespace Tests\Unit;

use App\Support\Format;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    public function test_rupiah_memakai_titik_ribuan_dan_membulatkan(): void
    {
        $this->assertSame('Rp 15.500', Format::rupiah(15500));
        $this->assertSame('Rp 15.500', Format::rupiah('15500.00'));
        $this->assertSame('Rp 15.500', Format::rupiah(15499.6));
        $this->assertSame('Rp 0', Format::rupiah(0));
        $this->assertSame('-', Format::rupiah(null));
    }

    public function test_persen_memakai_koma_tanda_plus_dan_tidak_menampilkan_minus_nol(): void
    {
        $this->assertSame('+20,0%', Format::percent(20.0));
        $this->assertSame('-3,2%', Format::percent(-3.24));
        $this->assertSame('0,0%', Format::percent(0.0));
        $this->assertSame('0,0%', Format::percent(0.04));
        $this->assertSame('0,0%', Format::percent(-0.04));
        $this->assertSame('+1.234,6%', Format::percent(1234.56));
        $this->assertSame('-', Format::percent(null));
    }

    public function test_tanggal_dari_teks_tanggal_jam_dan_objek(): void
    {
        $this->assertSame('18/10/2026', Format::date('2026-10-18'));
        $this->assertSame('18/10/2026', Format::date('2026-10-18 00:00:00'));
        $this->assertSame('18/10/2026', Format::date(new \DateTimeImmutable('2026-10-18 15:30:00')));
        $this->assertSame('-', Format::date(null));
        $this->assertSame('-', Format::date(''));
    }

    public function test_jumlah_stok_tanpa_nol_di_belakang_koma(): void
    {
        $this->assertSame('100', Format::quantity(100000));
        $this->assertSame('1,5', Format::quantity(1500));
        $this->assertSame('0', Format::quantity(0));
        $this->assertSame('250,125', Format::quantity(250125));
        $this->assertSame('1.000', Format::quantity(1000000));
        $this->assertSame('1.234.567,89', Format::quantity(1234567890));
        $this->assertSame('-1,5', Format::quantity(-1500));
    }
}
