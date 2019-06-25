<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class Permission extends Model
{
    //1=>前台资源 2=>控台资源
    const VISIABLE_OUTSIDE = 1;
    const VISIABLE_ADMIN = 2;

    //默认资源 1=>大B 2=>小b 3=>大B小b都有 4=>供应商
    const DEFAULT_HOSPITAL = 1;
    const DEFAULT_SLAVE = 2;
    const DEFAULT_BOTH = 3;
    const DEFAULT_SUPPLIER = 4;

    //拥有的权限 默认资源"|"运算
    const HOSPITAL = [1, 3, 5, 7];
    const SUPPLIER = [4, 5, 6, 7];
    const SLAVE = [2, 3, 6, 7];

    public $Id;

    public $Name;

    public $Visiable;

    public $Resource;

    public $Default;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'Permission';
    }
}
