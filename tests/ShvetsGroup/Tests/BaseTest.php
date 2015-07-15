<?php

namespace ShvetsGroup\Tests;

define('BASE_PATH', __DIR__ . "/../../../");

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/includes/helpers.php';

use Symfony\Component\Console as Console;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

abstract class BaseTest extends \PHPUnit_Framework_TestCase
{

    protected $container;

    protected function setUp()
    {
        global $container;
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__));
        $loader->load(BASE_PATH . 'app/config/config_test.yml');
        $container->get('database');
        $this->container = $container;
    }

    protected function tearDown()
    {
        unset($this->obj);
    }

    protected function assertArraysEqual($a, $b, $strict = false, $message = '')
    {
        if (count($a) !== count($b)) {
            $this->fail($message);
        }
        sort($a);
        sort($b);
        $this->assertTrue(($strict && $a === $b) || $a == $b, $message);
    }
}