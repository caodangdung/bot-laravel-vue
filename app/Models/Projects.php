<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Projects extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'name',
        'freed_camp_id',
        'git_lab_id',       
    ];

    protected $hidden = [
       'created_at',
       'updated_at'
    ];
}