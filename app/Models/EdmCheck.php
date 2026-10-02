<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The latest result of one automated deliverability check (DNS record, blocklist). */
class EdmCheck extends Model
{
    protected $fillable = ['kind', 'target', 'name', 'status', 'detail', 'checked_at', 'changed_at'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'changed_at' => 'datetime'];
    }

    /**
     * Store a result, keeping changed_at when the status is unchanged.
     * Returns [the row, whether the status changed].
     *
     * @return array{0: self, 1: bool}
     */
    public static function record(string $kind, string $target, string $name, string $status, ?string $detail = null): array
    {
        $row = self::firstOrNew(['kind' => $kind, 'target' => $target, 'name' => $name]);
        $changed = ! $row->exists || $row->status !== $status;

        $row->fill([
            'status' => $status,
            'detail' => $detail,
            'checked_at' => now(),
            'changed_at' => $changed ? now() : $row->changed_at,
        ])->save();

        return [$row, $changed];
    }
}
