<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Audit
{
    public static function log(
        string $action,
        string $entityType,
        int|string $entityId,
        ?array $old = null,
        ?array $new = null
    ): void {
        DB::table('audit_logs')->insert([
            'user_id'     => Auth::id(),
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => (string) $entityId,
            'old_values'  => $old === null ? null : json_encode($old),
            'new_values'  => $new === null ? null : json_encode($new),
            'ip_address'  => request()?->ip(),
            'user_agent'  => substr((string) request()?->userAgent(), 0, 255),
            'created_at'  => now(),
        ]);
    }
}
