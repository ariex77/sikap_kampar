<?php

namespace App\Console\Commands;

use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockWorkflow;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Perintah internal: menyetujui satu mutasi. Dipanggil berkali-kali secara bersamaan
 * oleh stock:parallel-check. Tidak untuk dipakai langsung.
 */
class StockApproveOne extends Command
{
    protected $signature = 'stock:approve-one {movement : ID mutasi} {user : ID penyetuju} {--at= : Waktu mulai (unix, detik) agar semua proses mulai serentak}';

    protected $description = 'Internal: menyetujui satu mutasi (dipakai oleh stock:parallel-check)';

    protected $hidden = true;

    public function handle(StockWorkflow $workflow): int
    {
        $user = User::findOrFail((int) $this->argument('user'));
        Auth::setUser($user);

        $movement = StockMovement::withoutGlobalScopes()->findOrFail((int) $this->argument('movement'));

        // Semua proses menunggu hingga waktu yang sama, supaya persetujuan benar-benar bertabrakan.
        $at = (float) $this->option('at');
        if ($at > 0) {
            $wait = $at - microtime(true);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
        }

        try {
            $workflow->approve($movement, $user);
        } catch (DomainException $e) {
            $this->line('DITOLAK: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line('DISETUJUI');

        return self::SUCCESS;
    }
}
