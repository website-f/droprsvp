<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Simple superadmin-editable key/value platform settings. */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->find($key)?->value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    /** Read a JSON setting as an array, merged shallowly over $default. */
    public static function getArray(string $key, array $default = []): array
    {
        $raw = static::get($key);
        $decoded = $raw ? json_decode($raw, true) : null;

        return is_array($decoded) ? array_replace($default, $decoded) : $default;
    }

    public static function putArray(string $key, array $value): void
    {
        static::put($key, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
