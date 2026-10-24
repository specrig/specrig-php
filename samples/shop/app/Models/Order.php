<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Order extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['id', 'amount', 'paid'];

    protected $casts = ['paid' => 'boolean'];
}
