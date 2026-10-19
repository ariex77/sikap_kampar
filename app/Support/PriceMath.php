<?php

namespace App\Support;

/** Hitungan harga murni (tanpa database) agar mudah diuji. */
class PriceMath
{
    /** Perubahan terhadap harga pembanding dalam persen, satu desimal; null bila tidak ada pembanding yang sah. */
    public static function changePercent(float $current, ?float $previous): ?float
    {
        if ($previous === null || $previous <= 0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * Dari baris yang sudah diurutkan dari terbaru, ambil hari terbaru dan hari sebelumnya.
     * Hanya satu baris per hari yang dipakai (baris pertama untuk hari yang sama).
     *
     * @param  list<array<string, mixed>>  $rowsNewestFirst  tiap baris memiliki kunci 'day'
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>}
     */
    public static function latestTwo(array $rowsNewestFirst): array
    {
        $seen = [];
        $picked = [];

        foreach ($rowsNewestFirst as $row) {
            $day = substr((string) $row['day'], 0, 10);

            if (isset($seen[$day])) {
                continue;
            }

            $seen[$day] = true;
            $picked[] = $row;

            if (count($picked) === 2) {
                break;
            }
        }

        return [$picked[0] ?? null, $picked[1] ?? null];
    }
}
