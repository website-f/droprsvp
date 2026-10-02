<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One signed change to an organizer's email quota. See App\Services\Edm\Credits. */
class EdmCreditEntry extends Model
{
    protected $table = 'edm_credit_ledger';

    protected $fillable = ['organizer_id', 'pool', 'period', 'delta', 'reason', 'campaign_id', 'purchase_id', 'note', 'created_by'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
