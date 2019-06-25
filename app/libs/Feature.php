<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2018/9/29
 * Time: 11:37
 */

namespace App\Libs;


class Feature
{
    const FRONTEND_PATTERN = APP_PATH . '/controllers/*Controller.php';

    const FRONTEND_NAMESPACE = 'App\Controllers';

    const BACKEND_PATTERN = APP_PATH . '/controllers/admin/*Controller.php';

    const BACKEND_NAMESPACE = 'App\Admin\Controllers';

    public static function allActions(): array
    {
        return array_merge(self::frontendActions(), self::backendActions());
    }

    public static function frontendActions(): array
    {
        return self::actions(self::FRONTEND_PATTERN, self::FRONTEND_NAMESPACE);
    }

    private static function actions(string $pattern, string $namespace)
    {
        $controllers = [];
        foreach (glob($pattern) as $controller) {
            $className = $namespace . '\\' . basename($controller, '.php');
            $ref = new \ReflectionClass($className);
            $methods = $ref->getMethods(\ReflectionMethod::IS_PUBLIC);
            $actions = [];
            foreach ($methods as $method) {
                if (\Phalcon\Text::endsWith($method->name, 'Action')) {
                    $actions[] = $method;
                }
            }
            if (count($actions) > 0) {
                $controllers[$className] = $actions;
            }
        }
        return $controllers;
    }

    public static function backendActions(): array
    {
        return self::actions(self::BACKEND_PATTERN, self::BACKEND_NAMESPACE);
    }
}