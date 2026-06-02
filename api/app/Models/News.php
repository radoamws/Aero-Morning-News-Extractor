<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class News extends Model
{
    protected $table = 't_news';
    protected $fillable = [
        'lang',
        'title',
        'content',
        'content_brut',
        'metadescription',
        'focuskeyphrase',
        'categories',
        'tags',
        'image_url',
        'wp_post_id',
        'status',
        'email_message_id'
    ];

    protected $casts = [
        'wp_post_id' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public const STATUS_PENDING = 0;
    public const STATUS_SYNCING = 1;
    public const STATUS_SYNCED = 2;

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSyncing(): bool
    {
        return $this->status === self::STATUS_SYNCING;
    }

    public function isSynced(): bool
    {
        return $this->status === self::STATUS_SYNCED;
    }

    public function getCategoriesArray(): array
    {
        if (!$this->categories) {
            return [];
        }
        return array_map('intval', array_filter(explode(',', $this->categories)));
    }

    public function getTagsArray(): array
    {
        if (!$this->tags) {
            return [];
        }
        return array_map('intval', array_filter(explode(',', $this->tags)));
    }
}
