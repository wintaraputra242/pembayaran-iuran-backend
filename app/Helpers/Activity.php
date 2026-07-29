<?php

use App\Models\ActivityLog;

if (!function_exists('activityLog')) {

    function activityLog(string $action, string $description)
    {
        ActivityLog::create([
            'id_user' => auth()->id(),
            'action' => $action,
            'description' => $description,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}