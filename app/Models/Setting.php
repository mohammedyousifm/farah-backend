<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value platform settings (JSON values), cached as one array.
 * Defaults live in config/farah.php; a stored setting overrides them.
 */
class Setting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        // memo(): the array is read from the cache store once per request, not once per setting
        $all = Cache::memo()->rememberForever('settings.all', fn () => static::pluck('value', 'key')->all());

        if (array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '') {
            return $all[$key];
        }

        return $default ?? config("farah.defaults.{$key}");
    }

    public static function put(string $key, mixed $value, string $group = 'general'): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
        Cache::memo()->forget('settings.all');
    }
}
