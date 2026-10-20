<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One outbound (or inbound) API call. Bodies and headers are stored already redacted; see ApiLogRedactor. */
class ApiLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['request_headers' => 'array', 'created_at' => 'datetime'];
    }
}
