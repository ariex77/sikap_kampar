<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Akun CONTOH untuk pengembangan. Jangan dipakai di server produksi.
 * Kata sandi diambil dari SEED_USER_PASSWORD di .env (atau nilai bawaan di bawah).
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('SEED_USER_PASSWORD', 'GantiSaya#2026');

        $dkpId = DB::table('organizations')
            ->where('name', 'Dinas Ketahanan Pangan Kabupaten Kampar')->value('id');
        $bangkinangId = DB::table('districts')->where('name', 'Bangkinang')->value('id');

        // [nama, email, peran]
        $users = [
            ['Admin Teknis',        'admin@sikap.test',              'super-admin-teknis'],
            ['Kepala Dinas',        'kepala@sikap.test',             'kepala-dinas'],
            ['Sekretariat',         'sekretariat@sikap.test',        'sekretariat'],
            ['Admin Bidang',        'adminbidang@sikap.test',        'admin-bidang'],
            ['Verifikator DKP',     'verifikator@sikap.test',        'verifikator-dkp'],
            ['Operator DKP',        'operator.dkp@sikap.test',       'operator-dkp'],
            ['Operator OPD',        'operator.opd@sikap.test',       'operator-opd'],
            ['Operator Kecamatan',  'operator.kecamatan@sikap.test', 'operator-kecamatan'],
            ['Auditor',             'auditor@sikap.test',            'auditor'],
        ];

        foreach ($users as [$name, $email, $role]) {
            $user = User::firstOrNew(['email' => $email]);

            if (! $user->exists) {
                $user->forceFill([
                    'name'              => $name,
                    'email'             => $email,
                    'password'          => Hash::make($password),
                    'email_verified_at' => now(),
                    'is_active'         => true,
                ])->save();
            }

            $user->syncRoles([$role]);

            // Pegawai DKP terhubung ke organisasi DKP
            if (! in_array($role, ['operator-opd', 'operator-kecamatan'], true) && $dkpId) {
                $this->insertIfMissing('user_organization',
                    ['user_id' => $user->id, 'organization_id' => $dkpId]);
            }

            // Cakupan data
            if ($role === 'operator-kecamatan') {
                // Contoh: hanya boleh bekerja di Kecamatan Bangkinang, untuk data harga
                $this->grantScope($user->id, 'district', $bangkinangId, 'harga');
            } elseif ($role !== 'operator-opd') {
                $this->grantScope($user->id, 'kabupaten', null, '*');
            }
        }
    }

    private function insertIfMissing(string $table, array $where): void
    {
        if (! DB::table($table)->where($where)->exists()) {
            DB::table($table)->insert($where + ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function grantScope(int $userId, string $type, ?int $scopeId, string $domain): void
    {
        $query = DB::table('user_scopes')
            ->where('user_id', $userId)
            ->where('scope_type', $type)
            ->where('data_domain', $domain);

        $scopeId === null ? $query->whereNull('scope_id') : $query->where('scope_id', $scopeId);

        if (! $query->exists()) {
            DB::table('user_scopes')->insert([
                'user_id'     => $userId,
                'scope_type'  => $type,
                'scope_id'    => $scopeId,
                'data_domain' => $domain,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }
}
