<?php

use Illuminate\Database\Capsule\Manager as DB;

/**
 * @return PDO
 */
function db($database = 'db')
{
    global $conf, $db;
    if (!isset($db[$database])) {
        try {
            $dsn = 'mysql:host=' . $conf[$database]['host'];
            $dsn .= ';port=' . (empty($conf[$database]['port']) ? 3306 : $conf[$database]['port']);
            $dsn .= ';dbname=' . $conf[$database]['database'];

            $db[$database] = new PDO($dsn, $conf[$database]['username'], $conf[$database]['password'], array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8") );

        } catch (PDOException $e) {
            print "Error!: " . $e->getMessage() . "<br/>";
            die();
        }
    }

    return $db[$database];
}

function close_db($database = 'db')
{
    global $conf, $db;
    if (isset($db[$database])) {
        $db[$database] = null;
    }
}

$capsule = new DB;
$capsule->addConnection(array(
    'driver'    => 'mysql',
    'host'      => 'localhost',
    'database'  => 'radadata',
    'username'  => 'root',
    'password'  => 'root',
    'charset'   => 'utf8',
    'collation' => 'utf8_unicode_ci',
    'prefix'    => ''
));
$capsule->setAsGlobal();
$capsule->bootEloquent();
