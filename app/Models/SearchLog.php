<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchLog extends Model
{
    protected $fillable = [
        'type',
        'query',
        'category_name',
        'user_id',
        'results_count',
        'response_summary',
    ];
}
