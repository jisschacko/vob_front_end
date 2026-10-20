<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashfreeVerification extends Model
{
    /** Statuses that will not change any more. */
    public const FINAL = ['SUCCESS', 'FAILURE', 'EXPIRED', 'MISMATCH'];

    protected $guarded = [];

    protected $hidden = ['payload', 'expected_fingerprint'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => 'datetime', 'reported_at' => 'datetime'];
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
