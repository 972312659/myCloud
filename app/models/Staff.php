<?php

namespace App\Models;

use App\Validators\Mobile;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Uniqueness;

class Staff extends Model
{
    //超级管理员id
    const ADMINISTRATION = 1;

    //默认密码
    const DEFAULT_PASSWORD = 123456;

    public $Id;

    public $Name;

    public $Phone;

    public $Password;

    public $Created;

    public $Role;

    public $WechartUserId;

    //验证
    private $selfValidate;

    //自定义验证场景
    const SCENE_STAFF_ADDSTAFF = 'staff-addstaff';

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'Staff';
    }

    /**
     * 设置验证场景
     * @param $scene
     */
    public function setScene($scene)
    {
        $this->selfValidate = new Validation();
        $fields = [
            'Name'          => [new PresenceOf(['message' => '请填写姓名'])],
            'Password'      => [new PresenceOf(['message' => '请填写密码'])],
            'Phone'         => [new PresenceOf(['message' => '请填写电话号码']), new Mobile(['message' => '请填写有效的手机号码']), new Uniqueness(['message' => '该电话号码已注册'])],
            'WechartUserId' => [new PresenceOf(['message' => '不能为空']), new Uniqueness(['message' => '该账号已被占用']),],
        ];
        $scenes = [
            self::SCENE_STAFF_ADDSTAFF => ['Name', 'Phone', 'WechartUserId'],
        ];
        foreach ($scenes[$scene] as $v) {
            $this->selfValidate->rules($v, $fields[$v]);
        }
    }

    public function validation()
    {
        if ($this->selfValidate) {
            return $this->validate($this->selfValidate);
        }
        return true;
    }

    /**
     * 根据部门选择接收消息的人员
     */
    public static function getStaffs($departmentId)
    {
        $staffs = StaffDepartment::find([
            'conditions' => 'DepartmentId=?0 and Switch=?1',
            'bind'       => [$departmentId, StaffDepartment::SWITCH_ON],
        ])->toArray();
        $userids = [];
        if (count($staffs)) {
            $staffs = Staff::query()->inWhere('Id', array_unique(array_column($staffs, 'StaffId')))->execute()->toArray();
            $userids = array_column($staffs, 'WechartUserId');
        }
        return $userids;
    }
}