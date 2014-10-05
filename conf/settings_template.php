<?php

// Rename this file to settings.php

require_once __DIR__ . '/../vendor/autoload.php';
use Aws\Common\Enum\Region;

global $conf;

$conf['db'] = array(
    'database' => 'dpro',
    'username' => 'root',
    'password' => 'root',
    'host' => 'localhost',
    'port' => '',
    'driver' => 'mysql',
    'prefix' => '',
);

$conf['db_local'] = array(
    'database' => 'dpro_local',
    'username' => 'root',
    'password' => 'root',
    'host' => 'localhost',
    'port' => '',
    'driver' => 'mysql',
    'prefix' => '',
);

$conf['aws'] = array(
    'key' => '09BX52FGR2GP4J0SX902',
    'secret' => 'KtgOGwg+Mmf9W+53ck86SSCI4Kuo+4Cjq3iHsvYo',
    'region' => Region::VIRGINIA
);

$conf['http_auth'] = array(
    'user' => 'dpro',
    'pass' => 'root',
);