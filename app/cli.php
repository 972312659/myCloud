<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/10/13
 * Time: 下午1:52
 */

use Phalcon\Di\FactoryDefault\Cli as CliDI;
use Phalcon\Cli\Console as ConsoleApp;
use Phalcon\Loader;

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

include APP_PATH . '/vendor/autoload.php';


// Using the CLI factory default services container
$di = new CliDI();

/**
 * Register the autoloader and tell it to register the tasks directory
 */
$loader = new Loader();

//$loader->register();

$loader->registerDirs([
    __DIR__ . '/tasks',
]);
$loader->registerNamespaces([
    'App\Controllers'       => __DIR__ . '/controllers',
    'App\Admin\Controllers' => __DIR__ . '/controllers/admin',
    'App\Models'            => __DIR__ . '/models',
    'App\Libs'              => __DIR__ . '/libs',
    'App\Plugins'           => __DIR__ . '/plugins',
    'App\Exceptions'        => __DIR__ . '/exceptions',
    'App\Enums'             => __DIR__ . '/enums',
]);

$loader->register();
// Load the configuration file (if any)
$configFile = __DIR__ . '/config/config.ini';

if (is_readable($configFile)) {
    $di->setShared('config', function () use ($configFile) {
        return new \Phalcon\Config\Adapter\Ini($configFile);
    });

    $di->setShared('logger', function () {
        return new \Phalcon\Logger\Adapter\File(__DIR__ . '/logs/debug.log');
    });

    $di->setShared('db', function () use ($di) {
        $config = $this->getConfig();
        $params = [
            'host'     => $config->mysql->host,
            'username' => $config->mysql->username,
            'password' => $config->mysql->password,
            'dbname'   => $config->mysql->dbname,
            'charset'  => $config->mysql->charset,
            'options'  => [
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        ];
        $db = new Phalcon\Db\Adapter\Pdo\Mysql($params);
//        $em = $di->getShared('eventsManager');
//        $em->attach('db', new App\Plugins\DatabaseListener($di));
//        $db->setEventsManager($em);
        return $db;
    });

    $di->setShared('redis', function () {
        $config = $this->getConfig();
        $redis = new Redis();
        $redis->connect($config->redis->host, $config->redis->port);
        $redis->select($config->redis->index);
        return $redis;
    });

    $di->setShared('session', function () {
        $config = $this->getConfig();
        $session = new App\Libs\Session([
            'host'        => $config->redis->host,
            'port'        => $config->redis->port,
            'sessionName' => $config->application->sessionName,
            'index'       => $config->redis->index,
        ]);
        $session->start();
        return $session;
    });

    $di->setShared('modelsMetadata', function () {
        $config = $this->getConfig();
        return new Phalcon\Mvc\Model\Metadata\Redis([
            'host'  => $config->redis->host,
            'port'  => $config->redis->port,
            'index' => $config->redis->index,
        ]);
    });

    $di->setShared('sphinx', function () {
        $config = $this->getConfig();
        $dsn = sprintf('mysql:host=%s;port=%s', $config->sphinx->host, $config->sphinx->port);
        return new PDO($dsn);
    });

    $di->setShared('qiniu', function () {
        $config = $this->getConfig();
        $accessKey = $config->qiniu->accessKey;
        $secretKey = $config->qiniu->secretKey;
        return new Qiniu\Auth($accessKey, $secretKey);
    });

    $di->setShared('mongodb.client', function () {
        $config = $this->getConfig();
        $dsn = $config->mongodb->dsn;
        if (!empty($config->mongodb->username)) {
            $opts = [
                'username'   => $config->mongodb->username,
                'password'   => $config->mongodb->password,
                'authSource' => $config->mongodb->authDatabase,
            ];
        } else {
            $opts = [];
        }

        return new \MongoDB\Client($dsn, $opts);
    });

    $di->setShared('mongodb.database', function () {
        $config = $this->getConfig();
        $database = $config->mongodb->database;

        return $this->getShared('mongodb.client')->selectDatabase($database);
    });
}

// Create a console application
$console = new ConsoleApp();

$console->setDI($di);

/**
 * Process the console arguments
 */
$arguments = [];

foreach ($argv as $k => $arg) {
    if ($k === 1) {
        $arguments['task'] = $arg;
    } elseif ($k === 2) {
        $arguments['action'] = $arg;
    } elseif ($k >= 3) {
        $arguments['params'][] = $arg;
    }
}

try {
    // Handle incoming arguments
    $console->handle($arguments);
} catch (\Phalcon\Exception $e) {
    // Do Phalcon related stuff here
    // ..
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} catch (\Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage() . PHP_EOL);
    exit(1);
} catch (\Exception $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
