<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AtributeProduct extends Model
{
    use HasFactory;

    protected $fillable = ['atribute_id', 'product_id'];

}
