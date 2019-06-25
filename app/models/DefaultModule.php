<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class DefaultModule extends Model
{
    public $Id;
    public $TypeCode;
    public $SysCode;
    public $ParentCode;
    public $ModuleCode;
    public $ValidTimeBeg;
    public $ValidTimeEnd;
    public $IsDisable;
    public $AddUser;
    public $AddTime;
    public $ModifyUser;
    public $ModifyTime;
    public $IsDelete;

    public function getSource()
    {
        return 'DefaultModule';
    }
}