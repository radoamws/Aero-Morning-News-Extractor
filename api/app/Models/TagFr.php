<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TagFr extends Model
{
    protected $table = 't_tags_fr';
    protected $fillable = ['wp_id', 'tag_name'];
}
