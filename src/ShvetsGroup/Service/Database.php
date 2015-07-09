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
    public static $config;

    public function __construct($config)
    {
        static::$config = $config;
        $this->connect();
    }

    public static function connect()
    {
        $capsule = new DB;
        $capsule->addConnection(static::$config);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }

    public static function disconnect()
    {
        DB::connection()->reconnect();
    }
}