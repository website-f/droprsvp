<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationCode extends Model
{
    protected $fillable = ['email', 'code_hash', 'expires_at', 'attempts'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
