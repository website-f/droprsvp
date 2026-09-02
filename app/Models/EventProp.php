<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A non-table fixture on the event floorplan (stage, entrance, buffet, dancefloor…)
 * so the table-management editor shows the room, not just tables floating in space.
 */
class EventProp extends Model
{
    /** kind => [default label, default width, default height] */
    public const KINDS = [
        'stage' => ['Stage', 260, 120],
        'entrance' => ['Entrance', 150, 60],
        'reception' => ['Reception', 180, 70],
        'catering' => ['Buffet', 300, 80],
        'gift' => ['Gift table', 170, 70],
        'booth' => ['Booth', 160, 100],
        'photo' => ['Photo booth', 160, 110],
        'dancefloor' => ['Dance floor', 220, 180],
        'vip' => ['VIP', 200, 90],
        'restroom' => ['Restroom', 130, 80],
        'walkway' => ['Walkway', 340, 60],
        'parking' => ['Parking', 240, 120],
        'custom' => ['Prop', 200, 90],
    ];

    protected $fillable = ['event_id', 'kind', 'label', 'color', 'pos_x', 'pos_y', 'width', 'height', 'rotation', 'sort_order'];

    protected function casts(): array
    {
        return ['pos_x' => 'integer', 'pos_y' => 'integer', 'width' => 'integer', 'height' => 'integer', 'rotation' => 'integer', 'sort_order' => 'integer'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
