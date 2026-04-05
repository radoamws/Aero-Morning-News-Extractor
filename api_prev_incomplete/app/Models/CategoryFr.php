<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoryFr extends Model
{
    protected $table = 't_categories_fr';
    protected $fillable = ['wp_id', 'categ_name'];
}
