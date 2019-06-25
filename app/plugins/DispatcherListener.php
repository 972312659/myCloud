<?php

namespace App\Plugins;

use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Feature;
use App\Libs\login\Expire;
use App\Libs\module\Manager;
use App\Libs\module\ManagerOrganization;
use App\Models\Action;
use App\Models\DefaultFeature;
use App\Models\Organization;
use App\Models\OrganizationFeature;
use App\Models\RoleFeature;
use Exception;
use Phalcon\DiInterface;
use Phalcon\Events\Event;
use Phalcon\Http\Response;
use Phalcon\Mvc\Dispatcher;
use Phalcon\Mvc\Dispatcher\Exception as DispatchException;

class DispatcherListener
{
    const ROUTE_KEY = '__CLOUD_ROUTES__';

    const DEFAULT_FEATURE_KEY = '__DEFAULT_FEATURE__';

    protected $di;

    /**
     * @var \Phalcon\Mvc\Model\Resultset\Simple
     */
    protected $routes;

    /**
     * @var array
     */
    protected $features;

    public function __construct(DiInterface $di)
    {
        $this->di = $di;
        $config = $di->get('config');
        $debug = $config->application->debug;
        if ($debug) {
            $this->routes = Action::find();
            // $this->features = DefaultFeature::find();
        } else {
            $this->routes = \apcu_entry(self::ROUTE_KEY, function () {
                return Action::find();
            });
        }
        $this->features = Manager::getDefaultFeature();
    }

    public function beforeException(Event $event, Dispatcher $dispatcher, Exception $exception)
    {
        if ($exception instanceof DispatchException) {
            switch ($exception->getCode()) {
                case Dispatcher::EXCEPTION_HANDLER_NOT_FOUND:
                case Dispatcher::EXCEPTION_ACTION_NOT_FOUND:
                    $dispatcher->forward([
                        'controller' => 'index',
                        'action'     => 'notFound',
                    ]);
                    return false;
            }
        }
        if ($exception instanceof LogicException || $exception instanceof ParamException) {
            $resp = new Response();
            $resp->setStatusCode($exception->getCode());
            $resp->setJsonContent($exception);
            $resp->send();
            return false;
        }
    }

    public function beforeExecuteRoute(Event $event, Dispatcher $dispatcher)
    {
        $controller = $dispatcher->getActiveController();
        $controllerName = $dispatcher->getControllerClass();
        $actionName = strtolower($dispatcher->getActionName() . $dispatcher->getActionSuffix());
        foreach ($this->routes as $route) {
            /**
             * @var $route Action;
             */
            if ($route->Controller === $controllerName && strtolower($route->Action) === $actionName) {
                if ($route->Discard === 1) {
                    throw new LogicException('接口已废弃.', Status::InternalServerError);
                }
                // 访问公开接口
                if ($route->Type === Action::Anonymous) {
                    return true;
                }
                // 登录用户接口
                $controller->inject();
                // 判断是否是前台
                if ($controller instanceof \App\Controllers\Controller) {
                    if (!$controller->user) {
                        throw new LogicException('接口需要登录后访问', Status::Unauthorized);
                    }
                    // 未绑定功能的路由视为登录后访问
                    if (empty($route->FeatureId)) {
                        return true;
                    }
                    //当前用户的featureId
                    $managerOrganization = new ManagerOrganization();
                    $roleFeature = $managerOrganization->roleFeature();

                    if (!in_array($route->FeatureId, $roleFeature, true)) {
                        throw new LogicException('没有对应的接口访问权限', Status::Forbidden);
                    }

                    $org = Organization::findFirst([
                        'conditions' => 'Id=?0',
                        'bind'       => [$controller->user->OrganizationId],
                        'cache'      => [
                            'key' => 'Cache:Organization:' . $controller->user->OrganizationId,
                        ],
                    ]);
                    //判断是否使用过期
                    if (Expire::judgePast($org)) {
                        throw new LogicException('平台使用已过期，请充值续费', Status::Forbidden);
                    }
                    return true;
                }

                if ($controller instanceof \App\Admin\Controllers\Controller) {
                    if (!$controller->staff) {
                        throw new LogicException('接口需要登录后访问', Status::Unauthorized);
                    }
                    // 未绑定功能的路由视为登录后访问
                    if (empty($route->FeatureId)) {
                        return true;
                    }
                    // 暂时都可访问
                    return true;
                }
            }
        }
        throw new LogicException('没有找到接口:' . $controllerName . '::' . $actionName, Status::NotFound);
    }
}
