<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Civilization extends Model
{
    use HasFactory;

    protected $fillable = [
        'nombre',
        'abrevacion',
        'numero',
    ];
}
