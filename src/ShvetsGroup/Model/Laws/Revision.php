<?php

namespace ShvetsGroup\Model\Laws;

use Illuminate\Database\Eloquent\Model;

class Revision extends Model
{
    const NEEDS_UPDATE = 0;
    const UP_TO_DATE = 1;
    const NO_TEXT = 5;

    protected $table = 'law_revisions';
    public $timestamps = false;
    public $fillable = ['id', 'date', 'law_id', 'state', 'text', 'text_updated', 'comment', 'status'];

    public static function find($law_id, $date)
    {
        return static::where('law_id', $law_id)->where('date', $date)->first();
    }
}