<?php

error_reporting(E_ALL & ~E_NOTICE);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

$di = new Phalcon\Di\FactoryDefault();
include APP_PATH . '/vendor/autoload.php';
include APP_PATH . '/config/services.php';
include APP_PATH . '/config/loader.php';
$config = $di->getShared('config');
if ($config->application->debug) {
    define('APP_DEBUG', true);
} else {
    define('APP_DEBUG', false);
}
$application = new \Phalcon\Mvc\Application($di);
$application->useImplicitView(false);
$application->handle()->send();
