<?php

namespace ShvetsGroup\Service;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Class Database
 * @package ShvetsGroup\Service
 *
 * Initialize Eloquent ORM database.
 */

class Database
{
    public function __construct($config)
    {
        $capsule = new DB;
        $capsule->addConnection($config);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }
}