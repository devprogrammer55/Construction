<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'description',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'category',
        'version',
        'version_notes',
        'uploaded_by',
        'is_current_version',
        'metadata',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'version' => 'integer',
        'is_current_version' => 'boolean',
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/' . $this->file_path);
    }

    public function getFileSizeFormattedAttribute(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function getCategoryColorAttribute(): string
    {
        return match ($this->category) {
            'plan' => '#3b82f6',
            'blueprint' => '#8b5cf6',
            'specification' => '#f97316',
            'contract' => '#ef4444',
            'report' => '#10b981',
            'permit' => '#fbbf24',
            'other' => '#6b7280',
            default => '#6b7280',
        };
    }

    public function scopeByProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeCurrentVersion($query)
    {
        return $query->where('is_current_version', true);
    }

    public function scopeByUploader($query, $userId)
    {
        return $query->where('uploaded_by', $userId);
    }

    public static function getNextVersionNumber($projectId, $name)
    {
        $latest = static::where('project_id', $projectId)
            ->where('name', $name)
            ->max('version');
        
        return ($latest ?? 0) + 1;
    }
}
