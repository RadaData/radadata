<?php

global $conf;
global $config_file;

$config_file = __DIR__ . '/../conf/conf.txt';
$data = file_exists($config_file) ? file_get_contents($config_file) : null;
$conf = $data ? unserialize($data) : array();

require_once __DIR__ . '/../conf/settings.php';


function variable_get($var, $default = NULL) {
  global $conf;
  return isset($conf[$var]) ? $conf[$var] : $default;
}

function variable_set($var, $value) {
  global $conf;
  global $config_file;
  $conf[$var] = $value;
  file_put_contents($config_file, serialize($conf));
}