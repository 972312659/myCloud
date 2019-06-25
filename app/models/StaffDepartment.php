<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class StaffDepartment extends Model
{
    //消息开关 0=>关 1=>开
    const SWITCH_OFF = 0;
    const SWITCH_ON = 1;

    public $StaffId;

    public $DepartmentId;

    public $Switch;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('StaffId', Staff::class, 'Id', ['alias' => 'Staff']);
        $this->belongsTo('DepartmentId', WechatDepartment::class, 'Id', ['alias' => 'Department']);
    }

    public function getSource()
    {
        return 'StaffDepartment';
    }
}