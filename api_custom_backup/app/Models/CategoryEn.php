<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoryEn extends Model
{
    protected $table = 't_categories_en';
    protected $fillable = ['wp_id', 'categ_name'];
}
