<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An address we must not mail again, from any list. */
class EmailSuppression extends Model
{
    protected $fillable = ['email', 'reason', 'detail'];
}
