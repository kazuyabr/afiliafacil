<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';
    protected $guarded = [];
    protected $hidden = ['password'];
    protected $casts = [
        'trial_until' => 'datetime',
        'active' => 'boolean',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function pages()
    {
        return $this->hasMany(Page::class, 'user_id');
    }

    public function storageConfig()
    {
        return $this->hasOne(StorageConfig::class, 'user_id');
    }

    public function isMaster(): bool
    {
        return $this->role && $this->role->name === 'master';
    }

    public function can(string $permission): bool
    {
        if (!$this->role) return false;
        return $this->role->hasPermission($permission);
    }
}
