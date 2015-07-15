<?php

namespace ShvetsGroup\Model\Laws;

use Illuminate\Database\Eloquent\Model;

class Type extends Model
{
    const field_name = 'Види';

    public $timestamps = false;
    public $primaryKey = 'name';
    public $fillable = ['id', 'name'];
}

