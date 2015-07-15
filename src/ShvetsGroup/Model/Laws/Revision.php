<?php

namespace ShvetsGroup\Model\Laws;

use Illuminate\Database\Eloquent\Model;

class Revision extends Model
{
    const NEEDS_UPDATE = 0;
    const UP_TO_DATE = 2;
    const NO_TEXT = 5;

    protected $table = 'law_revisions';
    protected $primaryKey = 'date';
    public $timestamps = false;
    public $fillable = ['date', 'law_id', 'text', 'comment', 'status'];
}