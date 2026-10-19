<?php

namespace App\Services;

use App\Models\PriceObservation;
use App\Models\User;
use App\Support\Audit;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Impor harga dari berkas CSV dalam dua langkah: PRATINJAU (semua baris diperiksa, belum ada data
 * harga yang dibuat) lalu IMPOR (baris yang valid menjadi DRAF milik pengimpor).
 * Data impor tidak pernah langsung terbit: tetap melalui pengajuan dan verifikasi seperti input biasa.
 */
class PriceImporter
{
    public const MAX_ROWS = 2000;

    public const REQUIRED = ['tanggal', 'pasar', 'komoditas', 'harga'];

    private const ALIASES = [
        'tanggal'    => ['tanggal', 'tgl', 'date'],
        'pasar'      => ['pasar', 'market'],
        'komoditas'  => ['komoditas', 'komoditi', 'commodity'],
        'harga'      => ['harga', 'price'],
        'keterangan' => ['keterangan', 'sumber', 'catatan'],
    ];

    // ------------------------------------------------------------------
    // Membaca berkas (tanpa database, mudah diuji)
    // ------------------------------------------------------------------

    /**
     * @return array{rows: list<array{row: int, tanggal: string, pasar: string, komoditas: string, harga: string, keterangan: string}>, error: ?string}
     */
    public static function parseCsv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $firstLine = strtok($content, "\n") ?: '';

        $delimiter = ',';
        $best = substr_count($firstLine, ',');
        foreach ([';', "\t"] as $candidate) {
            if (substr_count($firstLine, $candidate) > $best) {
                $delimiter = $candidate;
                $best = substr_count($firstLine, $candidate);
            }
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = null;
        $map = [];
        $rows = [];
        $line = 0;

        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $line++;

            if ($cells === [null] || count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue; // baris kosong
            }

            if ($header === null) {
                $header = array_map(fn ($c) => mb_strtolower(trim((string) $c)), $cells);
                foreach (self::ALIASES as $field => $names) {
                    foreach ($header as $index => $name) {
                        if (in_array($name, $names, true)) {
                            $map[$field] = $index;
                            break;
                        }
                    }
                }

                $missing = array_values(array_diff(self::REQUIRED, array_keys($map)));
                if ($missing) {
                    fclose($stream);

                    return ['rows' => [], 'error' => 'Kolom wajib tidak ditemukan: ' . implode(', ', $missing) . '. Gunakan templat dari halaman ini.'];
                }

                continue;
            }

            $row = ['row' => $line];
            foreach (array_keys(self::ALIASES) as $field) {
                $row[$field] = isset($map[$field]) ? trim((string) ($cells[$map[$field]] ?? '')) : '';
            }
            $rows[] = $row;

            if (count($rows) > self::MAX_ROWS) {
                fclose($stream);

                return ['rows' => [], 'error' => 'Berkas terlalu besar: maksimal ' . self::MAX_ROWS . ' baris per impor. Pecah menjadi beberapa berkas.'];
            }
        }

        fclose($stream);

        if ($header === null) {
            return ['rows' => [], 'error' => 'Berkas kosong.'];
        }
        if ($rows === []) {
            return ['rows' => [], 'error' => 'Berkas hanya berisi judul kolom, tidak ada baris data.'];
        }

        return ['rows' => $rows, 'error' => null];
    }

    /** Menerima 2026-10-19, 19/10/2026, 19-10-2026. Hasil Y-m-d atau null. */
    public static function parseDate(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/', $value, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $value, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** Menerima 15500, 15.500, 15,500, 15.500,50, "Rp 15.500". Hasil angka atau null. */
    public static function parsePrice(string $value): ?float
    {
        $v = trim(preg_replace('/^\s*rp\.?\s*/i', '', trim($value)));
        $v = str_replace(["\u{00A0}", ' '], '', $v);

        if ($v === '' || ! preg_match('/^\d[\d.,]*$/', $v)) {
            return null;
        }

        $hasDot = str_contains($v, '.');
        $hasComma = str_contains($v, ',');

        if ($hasDot && $hasComma) {
            // Pemisah yang terakhir adalah desimal.
            if (strrpos($v, ',') > strrpos($v, '.')) {
                $v = str_replace(',', '.', str_replace('.', '', $v));
            } else {
                $v = str_replace(',', '', $v);
            }
        } elseif ($hasDot) {
            // 15.500 atau 1.250.000 = pemisah ribuan; 15.5 = desimal.
            if (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
                $v = str_replace('.', '', $v);
            }
        } elseif ($hasComma) {
            $v = preg_match('/^\d{1,3}(,\d{3})+$/', $v) ? str_replace(',', '', $v) : str_replace(',', '.', $v);
        }

        return is_numeric($v) ? (float) $v : null;
    }

    // ------------------------------------------------------------------
    // Pratinjau
    // ------------------------------------------------------------------

    /**
     * Memeriksa seluruh baris dan menyimpan hasilnya di import_logs / import_rows.
     * Tidak membuat data harga apa pun.
     *
     * @return int id import_logs
     */
    public function preview(string $content, string $originalName, string $storedPath, User $user): int
    {
        $parsed = self::parseCsv($content);

        if ($parsed['error'] !== null) {
            throw new DomainException($parsed['error']);
        }

        $dates = array_filter(array_map(fn ($r) => self::parseDate($r['tanggal']), $parsed['rows']));
        $context = $this->context($user, $dates ? min($dates) : null, $dates ? max($dates) : null);
        $results = [];
        $seen = [];

        foreach ($parsed['rows'] as $raw) {
            [$normalized, $errors] = $this->check($raw, $context);

            $result = $errors ? 'error' : 'valid';

            if (! $errors) {
                $key = $this->key($normalized['market_id'], $normalized['commodity_id'], $normalized['observed_on']);

                if (isset($seen[$key])) {
                    $result = 'duplicate';
                    $errors[] = 'Ganda di dalam berkas (sama dengan baris ' . $seen[$key] . ').';
                } elseif (isset($context['existing'][$key])) {
                    $result = 'duplicate';
                    $errors[] = 'Harga untuk pasar, komoditas, dan tanggal ini sudah ada di sistem.';
                } else {
                    $seen[$key] = $raw['row'];
                }
            }

            $results[] = [
                'row'     => $raw['row'],
                'payload' => $normalized + ['raw' => $raw],
                'result'  => $result,
                'errors'  => $errors,
            ];
        }

        return DB::transaction(function () use ($results, $originalName, $storedPath, $user) {
            $counts = array_count_values(array_column($results, 'result'));

            $logId = DB::table('import_logs')->insertGetId([
                'import_type'       => 'price',
                'original_filename' => mb_substr($originalName, 0, 255),
                'file_path'         => mb_substr($storedPath, 0, 255),
                'status'            => 'previewed',
                'total_rows'        => count($results),
                'valid_rows'        => $counts['valid'] ?? 0,
                'error_rows'        => count($results) - ($counts['valid'] ?? 0),
                'imported_by'       => $user->id,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            foreach (array_chunk($results, 200) as $chunk) {
                DB::table('import_rows')->insert(array_map(fn ($r) => [
                    'import_log_id' => $logId,
                    'row_number'    => $r['row'],
                    'payload'       => json_encode($r['payload']),
                    'result'        => $r['result'],
                    'errors'        => $r['errors'] ? json_encode($r['errors']) : null,
                ], $chunk));
            }

            return $logId;
        });
    }

    // ------------------------------------------------------------------
    // Impor
    // ------------------------------------------------------------------

    /**
     * Membuat draf dari baris yang valid. Dijalankan sekali saja per pratinjau.
     * Semua pemeriksaan diulang karena data bisa berubah sejak pratinjau.
     *
     * @return array{imported: int, skipped: int, submitted: int, submit_failed: int}
     */
    public function commit(int $logId, User $user, bool $submit = false): array
    {
        $created = [];
        $skipped = 0;

        DB::transaction(function () use ($logId, $user, &$created, &$skipped) {
            $log = DB::table('import_logs')->where('id', $logId)->lockForUpdate()->first();

            if (! $log || (int) $log->imported_by !== $user->id) {
                throw new DomainException('Impor tidak ditemukan.');
            }
            if ($log->status !== 'previewed') {
                throw new DomainException('Impor ini sudah diproses. Unggah ulang berkas bila perlu.');
            }

            $rows = DB::table('import_rows')->where('import_log_id', $logId)->where('result', 'valid')->orderBy('row_number')->get();
            $dates = $rows->map(fn ($r) => json_decode($r->payload, true)['observed_on'] ?? null)->filter()->values();

            $context = $this->context($user, $dates->min(), $dates->max());
            $organizationId = DB::table('user_organization')->where('user_id', $user->id)->value('organization_id');
            $seen = [];

            foreach ($rows as $row) {
                $payload = json_decode($row->payload, true);
                [$normalized, $errors] = $this->check($payload['raw'], $context);

                if (! $errors) {
                    $key = $this->key($normalized['market_id'], $normalized['commodity_id'], $normalized['observed_on']);
                    if (isset($seen[$key]) || isset($context['existing'][$key])) {
                        $errors[] = 'Harga untuk pasar, komoditas, dan tanggal ini sudah ada di sistem.';
                    } else {
                        $seen[$key] = true;
                    }
                }

                if ($errors) {
                    $skipped++;
                    DB::table('import_rows')->where('id', $row->id)->update([
                        'result' => 'error',
                        'errors' => json_encode($errors),
                    ]);

                    continue;
                }

                $price = new PriceObservation();
                $price->uuid = (string) Str::uuid();
                $price->market_id = $normalized['market_id'];
                $price->district_id = $normalized['district_id'];
                $price->commodity_id = $normalized['commodity_id'];
                $price->unit_id = $normalized['unit_id'];
                $price->observed_on = $normalized['observed_on'];
                $price->price = $normalized['price'];
                $price->source_type = 'import';
                $price->source_reference = $normalized['source_reference'] ?: 'Impor ' . $log->original_filename;
                $price->status = 'draft';
                $price->created_by = $user->id;
                $price->updated_by = $user->id;
                $price->organization_id = $organizationId;

                try {
                    $price->save();
                } catch (UniqueConstraintViolationException) {
                    throw new DomainException('Ada data ganda yang tersimpan bersamaan. Ulangi dari pratinjau.');
                }

                DB::table('import_rows')->where('id', $row->id)->update(['result' => 'imported']);
                $created[] = $price;
            }

            DB::table('import_logs')->where('id', $logId)->update([
                'status'       => 'committed',
                'valid_rows'   => count($created),
                'error_rows'   => $log->total_rows - count($created),
                'committed_at' => now(),
                'updated_at'   => now(),
            ]);

            Audit::log('imported', 'price_import', $logId, null, [
                'file'     => $log->original_filename,
                'imported' => count($created),
                'skipped'  => $log->total_rows - count($created),
            ]);
        });

        $submitted = 0;
        $submitFailed = 0;

        if ($submit) {
            $workflow = app(PriceWorkflow::class);
            foreach ($created as $price) {
                try {
                    $workflow->submit($price, $user);
                    $submitted++;
                } catch (DomainException) {
                    $submitFailed++;
                }
            }
        }

        $totalRows = (int) DB::table('import_logs')->where('id', $logId)->value('total_rows');

        return [
            'imported'      => count($created),
            'skipped'       => $totalRows - count($created),
            'submitted'     => $submitted,
            'submit_failed' => $submitFailed,
        ];
    }

    // ------------------------------------------------------------------
    // Pemeriksaan satu baris
    // ------------------------------------------------------------------

    /** Data acuan sekali muat: pasar dalam cakupan, komoditas aktif, periode terkunci, harga yang sudah ada. */
    private function context(User $user, ?string $from, ?string $to): array
    {
        $markets = [];
        foreach (app(DataScope::class)->markets($user)->get() as $m) {
            $markets[Str::lower(trim($m->name))][] = $m;
        }

        $commodities = [];
        foreach (DB::table('commodities')->where('is_active', true)->get() as $c) {
            $commodities[Str::lower(trim($c->name))][] = $c;
            $commodities[Str::lower(trim($c->code))][] = $c;
        }

        $locked = DB::table('reporting_periods')->where('status', 'locked')->get(['start_date', 'end_date'])
            ->map(fn ($p) => [substr($p->start_date, 0, 10), substr($p->end_date, 0, 10)])->all();

        $existing = [];
        DB::table('price_observations')->whereNull('deleted_at')
            ->when(! $from && ! $to, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($from, fn ($q) => $q->whereDate('observed_on', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('observed_on', '<=', $to))
            ->get(['market_id', 'commodity_id', 'observed_on'])
            ->each(function ($r) use (&$existing) {
                $existing[$this->key($r->market_id, $r->commodity_id, substr((string) $r->observed_on, 0, 10))] = true;
            });

        return compact('markets', 'commodities', 'locked', 'existing');
    }

    private function key(int|string $market, int|string $commodity, string $date): string
    {
        return "$market|$commodity|$date";
    }

    /** @return array{0: array<string, mixed>, 1: list<string>} */
    private function check(array $raw, array $context): array
    {
        $errors = [];
        $n = ['source_reference' => $raw['keterangan'] ?? ''];

        // Tanggal
        $date = self::parseDate($raw['tanggal'] ?? '');
        if ($date === null) {
            $errors[] = 'Tanggal tidak valid (gunakan 19/10/2026 atau 2026-10-19).';
        } elseif ($date > today()->toDateString()) {
            $errors[] = 'Tanggal tidak boleh di masa depan.';
        } else {
            foreach ($context['locked'] as [$start, $end]) {
                if ($date >= $start && $date <= $end) {
                    $errors[] = 'Periode pelaporan untuk tanggal ini sudah dikunci.';
                    break;
                }
            }
        }
        $n['observed_on'] = $date;

        // Pasar
        $found = $context['markets'][Str::lower(trim($raw['pasar'] ?? ''))] ?? [];
        if (count($found) === 1) {
            $n['market_id'] = $found[0]->id;
            $n['district_id'] = $found[0]->district_id;
        } else {
            $errors[] = count($found) > 1
                ? 'Nama pasar ganda di beberapa kecamatan, hubungi admin.'
                : 'Pasar "' . ($raw['pasar'] ?? '') . '" tidak ditemukan atau di luar wilayah kerja Anda.';
        }

        // Komoditas
        $found = $context['commodities'][Str::lower(trim($raw['komoditas'] ?? ''))] ?? [];
        $found = collect($found)->unique('id')->values();
        if ($found->count() === 1) {
            $n['commodity_id'] = $found[0]->id;
            $n['unit_id'] = $found[0]->default_unit_id;
        } else {
            $errors[] = $found->count() > 1
                ? 'Nama komoditas ambigu.'
                : 'Komoditas "' . ($raw['komoditas'] ?? '') . '" tidak ditemukan.';
        }

        // Harga
        $price = self::parsePrice($raw['harga'] ?? '');
        if ($price === null || $price <= 0) {
            $errors[] = 'Harga harus berupa angka lebih dari 0.';
        } elseif ($price > 99999999999) {
            $errors[] = 'Harga terlalu besar.';
        }
        $n['price'] = $price;

        if (mb_strlen($n['source_reference']) > 255) {
            $errors[] = 'Keterangan maksimal 255 karakter.';
        }

        return [$n, $errors];
    }
}
