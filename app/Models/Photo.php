<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Photo extends Model
{
    use HasFactory;

    protected $fillable = [
        'album_id',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'title',
        'description',
        'tags',
        'location',
        'taken_at',
        'uploaded_by',
        'metadata',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'taken_at' => 'datetime',
        'tags' => 'array',
        'metadata' => 'array',
    ];

    public function album(): BelongsTo
    {
        return $this->belongsTo(PhotoAlbum::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute()
    {
        return asset('storage/' . $this->file_path);
    }

    public function getThumbnailUrlAttribute()
    {
        return asset('storage/' . str_replace('photos/', 'photos/thumbnails/', $this->file_path));
    }

    public function getFileSizeFormattedAttribute()
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
