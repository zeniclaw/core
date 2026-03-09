<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedArticle extends Model
{
    protected $fillable = [
        'user_phone',
        'url',
        'title',
        'source',
    ];
}
