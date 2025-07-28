<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhotoAlbum extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'description',
        'cover_photo',
        'created_by',
        'is_public',
        'tags',
        'metadata',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'tags' => 'array',
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    public function getPhotoCountAttribute()
    {
        return $this->photos()->count();
    }

    public function getCoverPhotoUrlAttribute()
    {
        return $this->cover_photo ? asset('storage/' . $this->cover_photo) : null;
    }
}
