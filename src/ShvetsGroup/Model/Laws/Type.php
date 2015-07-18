<?php

namespace ShvetsGroup\Model\Laws;

use Illuminate\Database\Eloquent\Model;

class Type extends Model
{
    const FIELD_NAME = 'Види';

    public $timestamps = false;
    public $primaryKey = 'name';
    public $fillable = ['id', 'name'];
}

