<?php

global $conf;

$conf['db'] = array(
    'database' => 'radadata',
    'username' => 'root',
    'password' => 'root',
    'host' => 'localhost',
    'port' => '3306',
    'driver' => 'mysql',
    'prefix' => '',
);
$conf['misc'] = array(
  'database' => 'radadata_misc',
  'username' => 'root',
  'password' => 'root',
  'host' => 'localhost',
  'port' => '3306',
  'driver' => 'mysql',
  'prefix' => '',
);
$conf['phantomjs'] = '/usr/local/bin/phantomjs';

$conf['aws'] = array(
  'profile' => 'default',
  'region' => 'eu-west-1'
);

$conf['use_proxy'] = 0;