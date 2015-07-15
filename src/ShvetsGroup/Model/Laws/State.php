<?php

namespace ShvetsGroup\Model\Laws;

use Illuminate\Database\Eloquent\Model;

class State extends Model
{
    const field_name = 'Стан';

    public $timestamps = false;
    public $primaryKey = 'name';
    public $fillable = ['id', 'name'];
}