<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TagEn extends Model
{
    protected $table = 't_tags_en';
    protected $fillable = ['wp_id', 'tag_name'];
}
