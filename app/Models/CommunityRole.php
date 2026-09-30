<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunityRole extends Model
{
    protected $fillable = [
        'community_id',
        'name',
        'permissions',
        'is_system',
        'color',
        'position',
        'hoist',
        'mentionable',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_system' => 'boolean',
            'hoist' => 'boolean',
            'mentionable' => 'boolean',
            'is_default' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(CommunityMember::class, 'community_role_id');
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? [], true);
    }

    /**
     * Discord falls back to the default text color when a role has no color.
     */
    public function colorOrDefault(): string
    {
        return $this->color ?: 'var(--text-main)';
    }
}
