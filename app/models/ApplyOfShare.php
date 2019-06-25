<?php

namespace App\Models;

use App\Libs\Sphinx;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;

class ApplyOfShare extends Model
{
    //审核状体 0=>待审 1=>通过 2=>未通过'
    const WAIT = 0;
    const PASS = 1;
    const UNPASS = 2;

    public $Id;

    public $OrganizationId;

    public $IsHospital;

    public $SectionId;

    public $DoctorId;

    public $EquipmentId;

    public $ComboId;

    public $Status;

    public $Remark;

    public $StartTime;

    public $EndTime;

    //验证
    private $selfValidate;

    //自定义验证场景
    const SCENE_CREATE = 'apply-hospital';

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('OrganizationId', Organization::class, 'Id', ['alias' => 'Organization']);
        $this->belongsTo('SectionId', Section::class, 'Id', ['alias' => 'Section']);
    }

    public function getSource()
    {
        return 'ApplyOfShare';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->add(['OrganizationId'],
            new Digit([
                'message' => [
                    'OrganizationId' => 'SendHospitalId必须为整形数字',
                ],
            ])
        );
        return $this->validate($validator);
    }

    /**
     * 设置验证场景
     * @param $scene
     */
    public function setScene($scene)
    {
        $this->selfValidate = new Validation();
        $fields = [
            'OrganizationId' => [new Digit(['message' => ['OrganizationId' => 'OrganizationId必须为整形数字']])],
        ];
        $scenes = [
            self::SCENE_CREATE => ['OrganizationId'],
        ];
        foreach ($scenes[$scene] as $v) {
            $this->selfValidate->rules($v, $fields[$v]);
        }
    }
}