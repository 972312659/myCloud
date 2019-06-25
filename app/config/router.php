<?php
$router = new Phalcon\Mvc\Router(false);
$router->setDefaultController('index');
$router->setDefaultAction('index');
$router->removeExtraSlashes(true);
$router->setUriSource(Phalcon\Mvc\Router::URI_SOURCE_SERVER_REQUEST_URI);

$router->add('/', 'index::index');

$router->add('/:controller', [
    'controller' => 1,
]);

$router->add('/:controller/:action', [
    'controller' => 1,
    'action'     => 2,
]);

$router->add('/:controller/:action/:params', [
    'controller' => 1,
    'action'     => 2,
    'params'     => 3,
]);

$admin = new \Phalcon\Mvc\Router\Group([
    'namespace' => 'App\Admin\Controllers',
]);
$admin->setPrefix('/admin');

$admin->add('', 'index::index');

$admin->add('/:controller', [
    'controller' => 1,
]);

$admin->add('/:controller/:action', [
    'controller' => 1,
    'action'     => 2,
]);

$admin->add('/:controller/:action/:params', [
    'controller' => 1,
    'action'     => 2,
    'params'     => 3,
]);

$router->add('/apple-app-site-association', 'index::apple');

$router->mount($admin);

return $router;
