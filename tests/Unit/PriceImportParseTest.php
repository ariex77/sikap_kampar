<?php

namespace Tests\Unit;

use App\Services\PriceImporter;
use PHPUnit\Framework\TestCase;

class PriceImportParseTest extends TestCase
{
    public function test_tanggal_berbagai_format(): void
    {
        $this->assertSame('2026-10-19', PriceImporter::parseDate('2026-10-19'));
        $this->assertSame('2026-10-19', PriceImporter::parseDate('19/10/2026'));
        $this->assertSame('2026-10-05', PriceImporter::parseDate('5-10-2026'));
        $this->assertSame('2026-10-19', PriceImporter::parseDate('19.10.2026'));
        $this->assertSame('2026-10-19', PriceImporter::parseDate('2026-10-19 08:30:00'));
        $this->assertNull(PriceImporter::parseDate('31/02/2026'));
        $this->assertNull(PriceImporter::parseDate('besok'));
        $this->assertNull(PriceImporter::parseDate(''));
    }

    public function test_harga_berbagai_format(): void
    {
        $this->assertSame(15500.0, PriceImporter::parsePrice('15500'));
        $this->assertSame(15500.0, PriceImporter::parsePrice('15.500'));
        $this->assertSame(15500.0, PriceImporter::parsePrice('15,500'));
        $this->assertSame(15500.0, PriceImporter::parsePrice('Rp 15.500'));
        $this->assertSame(15500.0, PriceImporter::parsePrice('Rp15.500'));
        $this->assertSame(1250000.0, PriceImporter::parsePrice('1.250.000'));
        $this->assertSame(15500.5, PriceImporter::parsePrice('15.500,50'));
        $this->assertSame(15500.5, PriceImporter::parsePrice('15,500.50'));
        $this->assertSame(15.5, PriceImporter::parsePrice('15.5'));
        $this->assertSame(15.5, PriceImporter::parsePrice('15,5'));
        $this->assertNull(PriceImporter::parsePrice('abc'));
        $this->assertNull(PriceImporter::parsePrice('-100'));
        $this->assertNull(PriceImporter::parsePrice(''));
    }

    public function test_csv_titik_koma_dengan_bom_dan_urutan_kolom_bebas(): void
    {
        $csv = "\xEF\xBB\xBFHarga;Tanggal;Pasar;Komoditas\r\n15500;19/10/2026;Pasar A;Beras\r\n\r\n;;;\r\n15000;18/10/2026;Pasar A;Beras\r\n";

        $r = PriceImporter::parseCsv($csv);

        $this->assertNull($r['error']);
        $this->assertCount(2, $r['rows']);
        $this->assertSame(2, $r['rows'][0]['row']);
        $this->assertSame('15500', $r['rows'][0]['harga']);
        $this->assertSame('Pasar A', $r['rows'][0]['pasar']);
        $this->assertSame(5, $r['rows'][1]['row']); // nomor baris sesuai berkas, baris kosong dilewati
        $this->assertSame('', $r['rows'][1]['keterangan']);
    }

    public function test_csv_koma_dan_tanda_kutip(): void
    {
        $csv = "tanggal,pasar,komoditas,harga,keterangan\n2026-10-19,\"Pasar, Induk\",Beras,\"15.500\",\"a, b\"\n";

        $r = PriceImporter::parseCsv($csv);

        $this->assertSame('Pasar, Induk', $r['rows'][0]['pasar']);
        $this->assertSame('15.500', $r['rows'][0]['harga']);
        $this->assertSame('a, b', $r['rows'][0]['keterangan']);
    }

    public function test_csv_encoding_windows_dibaca(): void
    {
        $csv = mb_convert_encoding("tanggal;pasar;komoditas;harga\n2026-10-19;Pasar Pangkalan Kerinci Baru é;Beras;15500\n", 'Windows-1252', 'UTF-8');

        $r = PriceImporter::parseCsv($csv);

        $this->assertNull($r['error']);
        $this->assertStringContainsString('é', $r['rows'][0]['pasar']);
    }

    public function test_kolom_wajib_kosong_dan_berkas_tanpa_data(): void
    {
        $this->assertStringContainsString('komoditas', PriceImporter::parseCsv("tanggal;pasar;harga\n1;2;3\n")['error']);
        $this->assertSame('Berkas kosong.', PriceImporter::parseCsv('')['error']);
        $this->assertStringContainsString('tidak ada baris data', PriceImporter::parseCsv("tanggal;pasar;komoditas;harga\n")['error']);
    }

    public function test_batas_jumlah_baris(): void
    {
        $csv = "tanggal;pasar;komoditas;harga\n" . str_repeat("2026-10-19;A;B;1\n", PriceImporter::MAX_ROWS + 1);

        $this->assertStringContainsString('terlalu besar', PriceImporter::parseCsv($csv)['error']);
    }
}
