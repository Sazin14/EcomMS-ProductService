<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class SqlCompat
{
    /** Case-insensitive LIKE: "ilike" on PostgreSQL, plain "like" elsewhere (MySQL/SQLite are already case-insensitive). */
    public static function like(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }
}
