<?php

namespace App\Models;

use Phalcon\Mvc\Model;


class OrganizationModule extends Model
{
    public $Id;
    public $OrganizationId;
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
        return 'OrganizationModule';
    }

    public function beforeCreate()
    {
        $this->ValidTimeBeg = empty(trim($this->ValidTimeBeg)) ? '1900-01-01 00:00:00' : $this->ValidTimeBeg;
        $this->ValidTimeEnd = empty(trim($this->ValidTimeEnd)) ? '1900-01-01 00:00:00' : $this->ValidTimeEnd;
    }

    public function beforeUpdate()
    {
        $this->ValidTimeBeg = empty(trim($this->ValidTimeBeg)) ? '1900-01-01 00:00:00' : $this->ValidTimeBeg;
        $this->ValidTimeEnd = empty(trim($this->ValidTimeEnd)) ? '1900-01-01 00:00:00' : $this->ValidTimeEnd;
    }
}