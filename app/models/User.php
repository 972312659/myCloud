<?php

namespace App\Models;

use App\Libs\Sphinx;
use App\Validators\IDCardNo;
use App\Validators\Mobile;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Uniqueness;
use Phalcon\Validation\Validator\Digit;

class User extends Model
{
    //消息开关
    const SWITCH_ON = 1;
    const SWITCH_OFF = 2;

    //标签
    const LABEL_EXPERT = 1;//专家
    const LABEL_ADMISSION = 2; //全科接诊
    const LABEL_ADMIN = 10;//平台创建管理员

    //默认密码
    const DEFAULT_PASSWORD = 123456;

    public $Id;

    public $Name;

    public $Phone;

    public $IDnumber;

    public $Email;

    public $Password;

    public $Sex;

    public $AppId;

    public $Factory;

    public $ModelNumber;

    public $TransferAmount;

    public $EvaluateAmount;

    //验证
    private $selfValidate;

    //自定义验证场景
    const SCENE_USER_CREATE = 'user-create';
    const SCENE_USER_UPDATE = 'user-update';
    const SCENE_USER_UPDATEPHONE = 'user-updatePhone';
    const SCENE_USER_ADDSTAFF = 'user-addStaff';
    const SCENE_AUTH_EDIT = 'auth-edit';
    const SCENE_EVALUATE_CREATE = 'evaluate-create';
    const SCENE_ORGANIZATION_CREATE = 'organization-create';
    const SCENE_ADMIN_HOSPITAL_CREATE = 'admin-hospital-create';

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('OrganizationId', Organization::class, 'Id', ['alias' => 'Organization']);
        $this->belongsTo('SectionId', Section::class, 'Id', ['alias' => 'Section']);
        $this->hasMany('Id', Bill::class, 'UserId', ['alias' => 'Bills']);
        $this->hasMany('Id', Transfer::class, 'AcceptDoctorId', ['alias' => 'Transfers']);
    }

    public function getSource()
    {
        return 'User';
    }

    /**
     * 设置验证场景
     * @param $scene
     */
    public function setScene($scene)
    {
        $this->selfValidate = new Validation();
        $fields = [
            'Name'       => [new PresenceOf(['message' => '请填写姓名'])],
            'Password'   => [new PresenceOf(['message' => '请填写密码'])],
            'Phone'      => [new PresenceOf(['message' => '请填写电话号码']), new Mobile(['message' => '请填写有效的手机号码']), new Uniqueness(['message' => '该电话号码已注册'])],
            'Sex'        => [new PresenceOf(['message' => '请选择性别'])],
            'HasEasemob' => [new Digit(['message' => '环信的格式错误'])],
            'IDnumber'   => [new IDCardNo(['message' => '身份证号码错误'])],
        ];
        $scenes = [
            self::SCENE_USER_CREATE           => ['Name', 'Phone', 'Sex'],
            self::SCENE_USER_UPDATE           => ['Name', 'Sex', 'Phone'],
            self::SCENE_USER_UPDATEPHONE      => ['Phone'],
            self::SCENE_USER_ADDSTAFF         => ['Phone'],
            self::SCENE_AUTH_EDIT             => ['Password'],
            self::SCENE_EVALUATE_CREATE       => ['Score'],
            self::SCENE_ORGANIZATION_CREATE   => ['Name', 'Phone'],
            self::SCENE_ADMIN_HOSPITAL_CREATE => ['Name', 'Phone'],
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

    public function afterCreate()
    {
        $sphinx = new Sphinx($this->getDI()->getShared('sphinx'), 'user');
        $sphinx->save(['id' => $this->Id, 'name' => $this->Name]);
    }

    public function beforeUpdate()
    {
        $changed = (array)$this->getChangedFields();
        if (in_array('Name', $changed, true)) {
            $sphinx = new Sphinx($this->getDI()->getShared('sphinx'), 'user');
            $sphinx->save(['id' => $this->Id, 'name' => $this->Name]);
        }
    }
}