<?php

$config = $di->getConfig();
$loader = new \Phalcon\Loader();

$loader->registerNamespaces([
    'App\Controllers' => $config->application->controllersDir,
    'App\Admin\Controllers' => $config->application->adminControllersDir,
    'App\Models' => $config->application->modelsDir,
    "App\Validators" => $config->application->validatorsDir,
    'App\Plugins' => $config->application->pluginsDir,
    'App\Enums' => $config->application->enumsDir,
    'App\Libs' => $config->application->libsDir,
    'App\Libs\PaymentChannel' => $config->application->libsDir . 'paymentChannel',
    'App\Libs\ShoppingCart' => $config->application->libsDir . 'shoppingCart',
    'App\Libs\Order' => $config->application->libsDir . 'order',
    'App\Exceptions' => $config->application->exceptionsDir,
])->register();