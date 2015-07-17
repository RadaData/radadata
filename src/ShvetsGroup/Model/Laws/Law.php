<?php

namespace ShvetsGroup\Model\Laws;

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
    public $fillable = ['id', 'date', 'status', 'state', 'has_text', 'card', 'card_updated', 'text', 'active_revision', 'issuers', 'types'];

    public function issuers()
    {
        return $this->belongsToMany('\ShvetsGroup\Model\Laws\Issuer', 'law_issuers', 'law_id', 'issuer_name');
    }
    public function types()
    {
        return $this->belongsToMany('\ShvetsGroup\Model\Laws\Type', 'law_types', 'law_id', 'type_name');
    }
    public function state()
    {
        return $this->hasOne('\ShvetsGroup\Model\Laws\State', 'name', 'state');
    }
    public function revisions()
    {
        return $this->hasMany('\ShvetsGroup\Model\Laws\Revision', 'law_id');
    }
    public function active_revision()
    {
        return $this->hasOne('\ShvetsGroup\Model\Laws\Revision', 'date', 'active_revision');
    }

    /**
     * @return array
     */
    public function getTypes()
    {
        $result = [];
        $types = $this->types()->get()->all();
        array_walk($types, function($item) use (&$result) {
            $result[] = $item->name;
        });
        return $result;
    }

    /**
     * @return array
     */
    public function getIssuers()
    {
        $result = [];
        $issuers = $this->issuers()->get()->all();
        array_walk($issuers, function($item) use (&$result) {
            $result[] = $item->name;
        });
        return $result;
    }

    /**
     * @return Revision[]
     */
    public function getAllRevisions()
    {
        return $this->revisions()->all();
    }

    /**
     * @return Revision|null
     */
    public function getRevision($date)
    {
        return $this->revisions()->where('date', $date)->first();
    }

    /**
     * @return Revision
     */
    public function getActiveRevision()
    {
        return $this->active_revision()->first();
    }
}

