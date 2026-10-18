<?php

namespace App\Console\Commands;

use App\Services\StockLedger;
use Illuminate\Console\Command;

class ReconcileStock extends Command
{
    protected $signature = 'stock:reconcile';

    protected $description = 'Memeriksa apakah saldo stok sama dengan jumlah seluruh mutasi yang telah disetujui';

    public function handle(StockLedger $ledger): int
    {
        $mismatches = $ledger->reconcile();

        if ($mismatches === []) {
            $this->info('Saldo stok konsisten dengan seluruh mutasi yang disetujui.');

            return self::SUCCESS;
        }

        $this->error(count($mismatches) . ' saldo tidak cocok dengan mutasi:');
        $this->table(
            ['ID lokasi', 'ID komoditas', 'Saldo tersimpan', 'Hasil hitung mutasi'],
            array_map(fn ($r) => [$r['storage_location_id'], $r['commodity_id'], $r['balance'], $r['ledger']], $mismatches)
        );
        $this->line('Perintah ini hanya melaporkan; saldo tidak diubah otomatis. Telusuri penyebabnya lewat audit_logs.');

        return self::FAILURE;
    }
}
