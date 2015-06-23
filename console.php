<?php

define('BASE_PATH', __DIR__ . "/");

// console.php
require_once __DIR__.'/vendor/autoload.php';

require_once __DIR__.'/includes/helpers.php';

use Symfony\Component\Console as Console;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

global $container;
$container = new ContainerBuilder();
$loader = new YamlFileLoader($container, new FileLocator(__DIR__));
$loader->load('services.yml');

$application = new Console\Application('Demo', '1.0.0');
$application->add($container->get('discover_command'));
$application->add($container->get('update_command'));
$application->add($container->get('download_command'));
$application->add($container->get('check_command'));
$application->add($container->get('cleanup_command'));

$application->run();