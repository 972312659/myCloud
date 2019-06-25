<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class Module extends Model
{
    const SysCode_YunWeb = 'yun-web';

    const ModuleCode_Transfer = 'M_TRANSFER';

    public $Id;
    public $SysCode;
    public $SysName;
    public $ParentCode;
    public $ModuleCode;
    public $ModuleName;
    public $SortNo;
    public $AddUser;
    public $AddTime;
    public $ModifyUser;
    public $ModifyTime;
    public $IsDelete;

    public function getSource()
    {
        return 'Module';
    }
}