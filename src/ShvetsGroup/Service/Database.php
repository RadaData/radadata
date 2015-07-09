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
    public static $instance;
    private $capsule;

    public function __construct($config)
    {
        static::$config = $config;
        static::$instance = $this;
        $this->connect();
    }

    public static function connect()
    {
        static::$instance->capsule = new DB;
        static::$instance->capsule->addConnection(static::$config);
        static::$instance->capsule->setAsGlobal();
        static::$instance->capsule->bootEloquent();
    }

    public static function reconnect()
    {
        static::$instance->capsule->getDatabaseManager()->reconnect();
    }
}