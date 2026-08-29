<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    protected $fillable = ['name', 'email', 'phone', 'category', 'message', 'handled_at'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }
}
