<?php

namespace App\Console\Commands;

use App\Models\StockMovement;
use App\Services\StockLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Bukti nyata (bukan simulasi) bahwa penguncian saldo mencegah pengeluaran ganda.
 *
 * Membuat satu lokasi uji dengan saldo tertentu dan beberapa pengeluaran yang sudah diverifikasi
 * (total jauh melebihi saldo), lalu menyetujui SEMUANYA SERENTAK lewat proses PHP terpisah.
 * Hasil yang benar: hanya sebanyak yang muat dalam saldo yang disetujui, sisanya ditolak,
 * saldo akhir tidak negatif, dan rekonsiliasi cocok. Data uji dihapus setelah selesai.
 *
 * Hanya untuk lingkungan pengembangan (APP_ENV=local) dengan MySQL.
 */
class StockParallelCheck extends Command
{
    protected $signature = 'stock:parallel-check
        {--workers=6 : Jumlah persetujuan yang dijalankan bersamaan}
        {--qty=40 : Jumlah tiap pengeluaran}
        {--balance=100 : Saldo awal}';

    protected $description = 'Uji paralel di MySQL: beberapa persetujuan pengeluaran dijalankan serentak terhadap saldo yang sama';

    /** @var list<int> */
    private array $movementIds = [];

    /** @var list<int> */
    private array $userIds = [];

    private ?int $locationId = null;

    private ?int $commodityId = null;

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Perintah ini hanya boleh dijalankan di lingkungan pengembangan (APP_ENV=local).');

            return self::FAILURE;
        }

        if (DB::getDriverName() !== 'mysql') {
            $this->error('Uji ini hanya bermakna di MySQL (SQLite tidak mendukung penguncian baris).');

            return self::FAILURE;
        }

        $workers = max(2, (int) $this->option('workers'));
        $qty = max(1, (int) $this->option('qty'));
        $balance = (int) $this->option('balance');

        if ($balance < $qty) {
            $this->error('Saldo awal harus minimal sama dengan jumlah satu pengeluaran.');

            return self::FAILURE;
        }

        $this->info("Menyiapkan data uji: saldo {$balance}, {$workers} pengeluaran masing-masing {$qty}, disetujui serentak.");

        try {
            $approverId = $this->prepare($workers, $balance, $qty);
            $this->info('Menjalankan proses paralel (sekitar 5 detik)...');
            $results = $this->runParallel($approverId);

            return $this->evaluate($results, $workers, $balance, $qty);
        } catch (Throwable $e) {
            $this->error('Uji tidak dapat dijalankan: ' . $e->getMessage());

            return self::FAILURE;
        } finally {
            $this->cleanup();
        }
    }

    private function prepare(int $workers, int $balance, int $qty): int
    {
        $districtId = DB::table('districts')->value('id');
        if (! $districtId) {
            throw new RuntimeException('Belum ada kecamatan. Jalankan php artisan db:seed terlebih dahulu.');
        }

        $unitId = DB::table('units')->where('code', 'kg')->value('id') ?? DB::table('units')->value('id');
        if (! $unitId) {
            throw new RuntimeException('Belum ada satuan. Jalankan php artisan db:seed terlebih dahulu.');
        }

        $now = now();
        $ts = ['created_at' => $now, 'updated_at' => $now];

        $this->commodityId = (int) DB::table('commodities')->insertGetId([
            'code'            => 'ZZ-UJI-' . Str::upper(Str::random(6)),
            'name'            => 'ZZ Komoditas Uji Paralel',
            'category'        => 'Uji',
            'default_unit_id' => $unitId,
            'is_strategic'    => false,
            'is_active'       => true,
        ] + $ts);

        $this->locationId = (int) DB::table('storage_locations')->insertGetId([
            'district_id' => $districtId,
            'name'        => 'ZZ Lokasi Uji Paralel',
            'is_active'   => true,
        ] + $ts);

        $ids = [];
        foreach (['penginput', 'pemeriksa', 'penyetuju'] as $role) {
            $ids[$role] = (int) DB::table('users')->insertGetId([
                'name'      => 'ZZ Uji Paralel ' . $role,
                'email'     => 'uji-paralel-' . $role . '-' . Str::lower(Str::random(6)) . '@sikap.invalid',
                'password'  => Hash::make(Str::random(32)),
                'is_active' => true,
            ] + $ts);
        }
        $this->userIds = array_values($ids);

        $base = [
            'storage_location_id' => $this->locationId,
            'commodity_id'        => $this->commodityId,
            'unit_id'             => $unitId,
            'movement_date'       => today()->toDateString(),
            'reference_no'        => 'UJI-PARALEL',
            'created_by'          => $ids['penginput'],
        ] + $ts;

        $opening = (int) DB::table('stock_movements')->insertGetId($base + [
            'uuid'          => (string) Str::uuid(),
            'movement_type' => 'opening',
            'quantity'      => $balance,
            'status'        => 'approved',
            'verified_by'   => $ids['pemeriksa'],
            'approved_by'   => $ids['penyetuju'],
            'verified_at'   => $now,
            'approved_at'   => $now,
        ]);
        $this->movementIds[] = $opening;

        DB::table('food_stocks')->insert([
            'storage_location_id' => $this->locationId,
            'commodity_id'        => $this->commodityId,
            'unit_id'             => $unitId,
            'balance'             => $balance,
            'version'             => 1,
            'last_movement_id'    => $opening,
        ] + $ts);

        for ($i = 0; $i < $workers; $i++) {
            $this->movementIds[] = (int) DB::table('stock_movements')->insertGetId($base + [
                'uuid'          => (string) Str::uuid(),
                'movement_type' => 'out',
                'quantity'      => $qty,
                'counterparty'  => 'UJI PARALEL',
                'status'        => 'verified',
                'verified_by'   => $ids['pemeriksa'],
                'verified_at'   => $now,
            ]);
        }

        return $ids['penyetuju'];
    }

    /** @return array<int, array{exit: int|null, out: string, err: string}> */
    private function runParallel(int $approverId): array
    {
        // Semua proses menunggu hingga detik yang sama agar benar-benar bertabrakan.
        $startAt = microtime(true) + 5.0;

        $processes = [];
        foreach (array_slice($this->movementIds, 1) as $id) { // elemen pertama = stok awal
            $process = new Process(
                [PHP_BINARY, base_path('artisan'), 'stock:approve-one', (string) $id, (string) $approverId, '--at=' . $startAt],
                base_path()
            );
            $process->setTimeout(120);
            $process->start();
            $processes[$id] = $process;
        }

        $results = [];
        foreach ($processes as $id => $process) {
            $process->wait();
            $results[$id] = [
                'exit' => $process->getExitCode(),
                'out'  => trim($process->getOutput()),
                'err'  => trim($process->getErrorOutput()),
            ];
        }

        return $results;
    }

    /** @param array<int, array{exit: int|null, out: string, err: string}> $results */
    private function evaluate(array $results, int $workers, int $balance, int $qty): int
    {
        $approved = 0;
        $errors = 0;
        $rows = [];

        foreach ($results as $id => $r) {
            if (str_starts_with($r['out'], 'DISETUJUI')) {
                $approved++;
                $status = 'disetujui';
            } elseif (str_starts_with($r['out'], 'DITOLAK')) {
                $status = 'ditolak (saldo kurang)';
            } else {
                $errors++;
                $status = 'GALAT';
            }

            $detail = $r['out'] !== '' ? $r['out'] : $r['err'];
            $rows[] = [$id, $status, Str::limit(str_replace("\n", ' ', $detail), 90)];
        }

        $this->table(['ID mutasi', 'Hasil', 'Keterangan'], $rows);

        $expectedApproved = min($workers, intdiv($balance, $qty));
        $expectedFinal = ($balance - $expectedApproved * $qty) * 1000;

        $final = StockLedger::toMilli(
            DB::table('food_stocks')
                ->where('storage_location_id', $this->locationId)
                ->where('commodity_id', $this->commodityId)
                ->value('balance')
        );

        $mismatch = collect(app(StockLedger::class)->reconcile())
            ->where('storage_location_id', $this->locationId)
            ->count();

        $checks = [
            'Tidak ada galat atau deadlock'                                        => $errors === 0,
            "Tepat {$expectedApproved} pengeluaran disetujui (terhitung {$approved})" => $approved === $expectedApproved,
            'Saldo akhir ' . StockLedger::fromMilli($expectedFinal) . ' (terbaca ' . StockLedger::fromMilli($final) . ')' => $final === $expectedFinal,
            'Saldo tidak negatif'                                                  => $final >= 0,
            'Saldo cocok dengan jumlah seluruh mutasi (rekonsiliasi)'              => $mismatch === 0,
        ];

        $ok = true;
        foreach ($checks as $label => $passed) {
            $passed ? $this->info('  LULUS  ' . $label) : $this->error('  GAGAL  ' . $label);
            $ok = $ok && $passed;
        }

        $ok
            ? $this->info('Penguncian saldo bekerja: tidak ada pengeluaran ganda.')
            : $this->error('Ada pemeriksaan yang gagal. Simpan keluaran ini dan laporkan.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function cleanup(): void
    {
        try {
            if ($this->movementIds) {
                $morph = (new StockMovement())->getMorphClass();

                DB::table('approval_histories')
                    ->where('approvable_type', $morph)
                    ->whereIn('approvable_id', $this->movementIds)
                    ->delete();

                DB::table('audit_logs')
                    ->where('entity_type', 'stock_movement')
                    ->whereIn('entity_id', array_map('strval', $this->movementIds))
                    ->delete();

                DB::table('stock_movements')->whereIn('id', $this->movementIds)->delete();
            }

            if ($this->locationId) {
                DB::table('food_stocks')->where('storage_location_id', $this->locationId)->delete();
                DB::table('storage_locations')->where('id', $this->locationId)->delete();
            }

            if ($this->commodityId) {
                DB::table('commodities')->where('id', $this->commodityId)->delete();
            }

            if ($this->userIds) {
                DB::table('users')->whereIn('id', $this->userIds)->delete();
            }
        } catch (Throwable $e) {
            $this->warn('Pembersihan data uji tidak lengkap: ' . $e->getMessage());
            $this->warn('Hapus manual data berawalan "ZZ" (lokasi, komoditas, pengguna) bila masih ada.');
        }
    }
}
