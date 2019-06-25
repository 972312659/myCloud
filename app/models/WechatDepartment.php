<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class WechatDepartment extends Model
{
    const ROOT = 1;//根部门
    const REGISTRATION = 2;//抢号部

    public $Id;

    public $Name;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'WechatDepartment';
    }
}