<?php

define('BASE_PATH', __DIR__ . "/");

// console.php
require_once __DIR__.'/vendor/autoload.php';

require_once __DIR__.'/src/includes/helpers.php';

use Symfony\Component\Console as Console;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

global $container;
$container = new ContainerBuilder();
$loader = new YamlFileLoader($container, new FileLocator(__DIR__));

if (file_exists(BASE_PATH . 'app/config/config_prod.yml')) {
    $loader->load(BASE_PATH . 'app/config/config_prod.yml');
}
else {
    $loader->load(BASE_PATH . 'app/config/config.yml');
}

$container->get('database');

$application = new Console\Application('RadaDownloader', '1.0.0');
$application->add($container->get('status_command'));
$application->add($container->get('discover_command'));
$application->add($container->get('download_command'));
$application->add($container->get('check_command'));
$application->add($container->get('cleanup_command'));
$application->add($container->get('dump_command'));
$application->add($container->get('cron_command'));

$application->run();