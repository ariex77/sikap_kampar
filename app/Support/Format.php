<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;

/** Pemformatan angka dan tanggal untuk tampilan (format Indonesia). */
class Format
{
    public static function rupiah(float|int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return 'Rp ' . number_format((float) $value, 0, ',', '.');
    }

    public static function percent(?float $value): string
    {
        if ($value === null) {
            return '-';
        }

        $rounded = round($value, 1);
        if ($rounded == 0.0) {
            $rounded = 0.0; // hindari tampilan "-0,0%"
        }

        return ($rounded > 0 ? '+' : '') . number_format($rounded, 1, ',', '.') . '%';
    }

    public static function date(string|DateTimeInterface|null $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        $date = $value instanceof DateTimeInterface ? $value : new DateTimeImmutable(substr($value, 0, 10));

        return $date->format('d/m/Y');
    }

    /** Jumlah stok dari bilangan bulat seperseribu satuan, tanpa nol di belakang koma. */
    public static function quantity(int $milli): string
    {
        return rtrim(rtrim(number_format($milli / 1000, 3, ',', '.'), '0'), ',');
    }
}
