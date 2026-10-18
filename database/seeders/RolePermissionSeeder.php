<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'dashboard.view',
            'users.manage', 'roles.manage', 'master.manage',
            'price.view', 'price.create', 'price.submit', 'price.verify',
            'stock.view', 'stock.create', 'stock.submit', 'stock.verify', 'stock.approve',
            'indicator.view', 'indicator.manage', 'indicator.verify', 'indicator.approve',
            'report.view', 'report.export',
            'import.run',
            'publication.manage',
            'audit.view',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $roles = [
            // Admin teknis: kelola sistem, TIDAK boleh memverifikasi/menyetujui data substantif.
            'super-admin-teknis' => [
                'dashboard.view', 'users.manage', 'roles.manage', 'master.manage', 'audit.view',
            ],
            'kepala-dinas' => [
                'dashboard.view', 'price.view', 'stock.view', 'stock.approve',
                'indicator.view', 'indicator.approve',
                'report.view', 'report.export', 'publication.manage', 'audit.view',
            ],
            'sekretariat' => [
                'dashboard.view', 'price.view', 'stock.view',
                'indicator.view', 'indicator.manage', 'report.view', 'report.export',
            ],
            'admin-bidang' => [
                'dashboard.view',
                'price.view', 'price.create', 'price.submit',
                'stock.view', 'stock.create', 'stock.submit',
                'indicator.view', 'indicator.manage',
                'report.view', 'import.run',
            ],
            'verifikator-dkp' => [
                'dashboard.view', 'price.view', 'price.verify',
                'stock.view', 'stock.verify',
                'indicator.view', 'indicator.verify', 'report.view',
            ],
            'operator-dkp' => [
                'dashboard.view',
                'price.view', 'price.create', 'price.submit',
                'stock.view', 'stock.create', 'stock.submit',
                'import.run',
            ],
            'operator-opd' => [
                'dashboard.view', 'price.view', 'price.create', 'price.submit', 'stock.view',
            ],
            'operator-kecamatan' => [
                'dashboard.view', 'price.view', 'price.create', 'price.submit',
            ],
            'auditor' => [
                'dashboard.view', 'price.view', 'stock.view', 'indicator.view',
                'report.view', 'audit.view',
            ],
        ];

        foreach ($roles as $roleName => $perms) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($perms);
        }
    }
}
