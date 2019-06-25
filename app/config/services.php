<?php

Phalcon\Mvc\Model::setup([
    'notNullValidations' => false,
    'castOnHydrate'      => true,
]);

$di->setShared('config', function () {
    return new \Phalcon\Config\Adapter\Ini(APP_PATH . '/config/config.ini');
});

$di->setShared('logger', function () {
    return new \Phalcon\Logger\Adapter\File(APP_PATH . '/logs/debug.log');
});

$di->setShared('url', function () {
    $config = $this->getConfig();
    $url = new Phalcon\Mvc\Url();
    $url->setBaseUri($config->application->baseUri);
    return $url;
});

$di->set('dispatcher', function () use ($di) {
    $em = $di->getShared('eventsManager');
    $em->attach('dispatch', new App\Plugins\DispatcherListener($di));
    $dispatcher = new Phalcon\Mvc\Dispatcher();
    $dispatcher->setDefaultNamespace('App\Controllers');
    $dispatcher->setEventsManager($em);
    return $dispatcher;
});

$di->setShared('router', function () {
    return include __DIR__ . '/router.php';
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
    $em = $di->getShared('eventsManager');
    $em->attach('db', new App\Plugins\DatabaseListener($di));
    $db->setEventsManager($em);
    return $db;
});

$di->setShared('InquiryDB', function () use ($di) {
    $config = $this->getConfig();
    $params = [
        'host'     => $config->mysql->host,
        'username' => $config->mysql->username,
        'password' => $config->mysql->password,
        'dbname'   => 'InquiryDB',
        'charset'  => $config->mysql->charset,

    ];
    $db = new Phalcon\Db\Adapter\Pdo\Mysql($params);
    return $db;
});

$di->setShared('fluent', function () use ($di) {
    $connection = $di->getShared('db');
    $pdo = $connection->getInternalHandler();
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    return new FluentPDO($pdo);
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
    return new Phalcon\Mvc\Model\Metadata\Apcu([
        'prefix'   => 'CLOUD',
        'lifetime' => 1 << 22, // 大概48天左右
    ]);
});

$di->setShared('queue', function () {
    $config = $this->getConfig();
    return new \Pheanstalk\Pheanstalk($config->queue->host);
});

$di->setShared('qiniu', function () {
    $config = $this->getConfig();
    $accessKey = $config->qiniu->accessKey;
    $secretKey = $config->qiniu->secretKey;
    return new Qiniu\Auth($accessKey, $secretKey);
});

$di->setShared('sms', function () use ($di) {
    $queue = $di->getShared('queue');
    $session = $di->getShared('session');
    $redis = $di->getShared('redis');
    return new \App\Libs\Sms($queue, $session, $redis);
});

$di->setShared('push', function () use ($di) {
    $queue = $di->getShared('queue');
    return new \App\Libs\Push($queue);
});

$di->setShared('sphinx', function () {
    $config = $this->getConfig();
    $dsn = sprintf('mysql:host=%s;port=%s', $config->sphinx->host, $config->sphinx->port);
    return new PDO($dsn);
});

$di->setShared('wxpay', function () {
    $config = $this->getConfig();
    return \EasyWeChat\Factory::payment([
        'app_id'              => $config->wxpay->app_id,
        'mch_id'              => $config->wxpay->mch_id,
        'key'                 => $config->wxpay->key,
        'cert_path'           => $config->wxpay->cert_path,
        'key_path'            => $config->wxpay->key_path,
        'rsa_public_key_path' => $config->wxpay->rsa_public_key_path,
    ]);
});

$di->setShared('channels', function () {
    $channel = new \App\Enums\PaymentChannel();
    $channel->register(new \App\Libs\PaymentChannel\Alipay());
    $channel->register(new \App\Libs\PaymentChannel\Wxpay());
    return $channel;
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

$di->set('modelsCache', function () {
    $config = $this->getConfig();
    if ($config->application->debug) {
        $frontCache = new \Phalcon\Cache\Frontend\Data([
            'lifetime' => 5,
        ]);
    } else {
        $frontCache = new \Phalcon\Cache\Frontend\Data([
            'lifetime' => 86400,
        ]);
    }
    $cache = new \Phalcon\Cache\Backend\Redis($frontCache, [
        'host'       => $config->redis->host,
        'port'       => $config->redis->port,
        'persistent' => true,
        'index'      => $config->redis->index,
    ]);
    return $cache;
});

$di->setShared('order.manager', function () {
    return new \App\Libs\Order\Manager($this->get('db'));
});
