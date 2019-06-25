<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;

class Role extends Model
{
    //默认角色
    const DEFAULT_B = 2;
    const DEFAULT_b = 3;
    const DEFAULT_SUPPLIER = 4;

    //organization=0 控台角色区分
    const STAFF_ADMIN_REMOTE = 1;
    const STAFF_FRONT_REMOTE = 2;

    public $Id;

    public $Name;

    public $OrganizationId;

    public $Remark;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'Role';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('Name', [
            new PresenceOf(['message' => '名字不能为空']),
        ]);
        return $this->validate($validate);
    }

    public function beforeUpdate()
    {
        if (in_array($this->Id, [Role::DEFAULT_B, Role::DEFAULT_b, Role::DEFAULT_SUPPLIER])) {
            return false;
        }
        return true;
    }

    public function beforeDelete()
    {
        if (in_array($this->Id, [Role::DEFAULT_B, Role::DEFAULT_b, Role::DEFAULT_SUPPLIER])) {
            return false;
        }
        return true;
    }
}
