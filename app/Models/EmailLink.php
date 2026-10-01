<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A link inside a campaign, and how often it was clicked. */
class EmailLink extends Model
{
    protected $fillable = ['campaign_id', 'hash', 'url', 'clicks', 'unique_clicks'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'campaign_id');
    }
}
