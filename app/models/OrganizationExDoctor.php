<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Digit;

class OrganizationExDoctor extends Model
{
    public $OrganizationId;

    public $UserId;

    public $ExDoctorId;

    public $RegistrationFee;

    //验证
    private $selfValidate;

    //自定义验证场景
    const SCENE_REGISTRATION_RELATION = 'registration_relation';

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('OrganizationId', Organization::class, 'Id', ['alias' => 'Organization']);
        $this->belongsTo('UserId', User::class, 'Id', ['alias' => 'User']);
        $this->belongsTo('ExDoctorId', ExDoctor::class, 'Id', ['alias' => 'ExDoctor']);
    }

    public function getSource()
    {
        return 'OrganizationExDoctor';
    }

    public function validation()
    {
        if ($this->selfValidate) {
            return $this->validate($this->selfValidate);
        }
        return true;
    }

    /**
     * 设置验证场景
     * @param $scene
     */
    public function setScene($scene)
    {
        $this->selfValidate = new Validation();
        $fields = [
            'OrganizationId'  => [new Digit(['message' => '所属机构的格式错误'])],
            'UserId'          => [new Digit(['message' => '医生格式错误'])],
            'ExDoctorId'      => [new Digit(['message' => '外联医生格式格式错误'])],
            'RegistrationFee' => [new PresenceOf(['message' => '请填写挂号费']), new Digit([['message' => '挂号费必须是数字']])],
        ];
        $scenes = [
            self::SCENE_REGISTRATION_RELATION => ['OrganizationId', 'UserId', 'ExDoctorId', 'RegistrationFee'],
        ];
        foreach ($scenes[$scene] as $v) {
            $this->selfValidate->rules($v, $fields[$v]);
        }
    }
}