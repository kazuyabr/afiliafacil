<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'settings';
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];

    public static function getValue(string $key, $default = null)
    {
        $row = static::find($key);
        if (!$row || $row->value === null) return $default;
        $decoded = json_decode($row->value, true);
        return $decoded === null ? $row->value : $decoded;
    }

    public static function setValue(string $key, $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')]
        );
    }
}
