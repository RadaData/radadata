<?php

namespace ShvetsGroup\Model;

use Illuminate\Database\Eloquent\Model;

class Law extends Model
{
    const UNKNOWN = 0;
    const HAS_TEXT = 1;
    const NO_TEXT = 10;

    const NOT_DOWNLOADED = 0;
    const DOWNLOADED_BUT_NEEDS_UPDATE = 4;
    const DOWNLOADED_CARD = 5;
    const DOWNLOADED_REVISIONS = 10;
    const DOWNLOADED_RELATIONS = 15;
    const SAVED = 100;


    public $timestamps = false;
    public $fillable = ['id', 'date', 'status', 'has_text'];
}

