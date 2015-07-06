<?php

global $conf;

$conf['db'] = [
    'database' => 'radadata',
    'username' => 'root',
    'password' => 'root',
    'host'     => 'localhost',
    'port'     => '3306',
    'driver'   => 'mysql',
    'prefix'   => '',
];
$conf['misc'] = [
    'database' => 'radadata_misc',
    'username' => 'root',
    'password' => 'root',
    'host'     => 'localhost',
    'port'     => '3306',
    'driver'   => 'mysql',
    'prefix'   => '',
];
$conf['phantomjs'] = '/usr/local/bin/phantomjs';

$conf['aws'] = [
    'version' => 'latest',
    'profile' => 'default',
    'region'  => 'eu-west-1'
];

$conf['use_proxy'] = 0;