<?php

namespace ShvetsGroup\Model\Laws;

use Illuminate\Database\Eloquent\Model;

class State extends Model
{
    const FIELD_NAME = 'Стан';
    const STATE_UNKNOWN = 'Не визначено';

    public $timestamps = false;
    public $primaryKey = 'name';
    public $fillable = ['id', 'name'];
}