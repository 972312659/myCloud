<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class ModuleFeature extends Model
{
    public $Id;
    public $SysCode;
    public $ParentCode;
    public $ModuleCode;
    public $FeatureId;
    public $AddUser;
    public $AddTime;
    public $ModifyUser;
    public $ModifyTime;
    public $IsDelete;

    public function getSource()
    {
        return 'ModuleFeature';
    }
}