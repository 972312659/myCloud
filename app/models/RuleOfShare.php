<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Uniqueness;

class RuleOfShare extends Model
{
    //默认的分润规则
    const DEFAULT_B = 1;//对大B的
    const DEFAULT_b = 2;//对小b的

    //分润方式
    const RULE_FIXED = 1;//固定
    const RULE_RATIO = 2;//按比例

    //创建者 0=>平台 1=>医院创建分润组 2=>医院创建供应商
    const STYLE_PLATFORM = 0;
    const STYLE_HOSPITAL = 1;
    const STYLE_HOSPITAL_SUPPLIER = 2;

    public $Id;

    public $Name;

    public $Fixed;

    public $Ratio;

    public $DistributionOutB;

    public $DistributionOut;

    public $Intro;

    public $OrganizationId;

    public $Type;

    public $Remark;

    public $UpdateTime;

    public $Style;

    public $CreateOrganizationId;

    //验证
    private $selfValidate;

    //自定义验证场景
    const SCENE_RULE_CREATE = 'rule-create';
    const SCENE_ADMIN_HOSPITAL_CREATE = 'admin-hospital-create';
    const SCENE_SUPPLIER_CREATE = 'supplier_create';

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'RuleOfShare';
    }

    /**
     * 设置验证场景
     * @param $scene
     */
    public function setScene($scene)
    {
        $this->selfValidate = new Validation();
        $fields = [
            'Name'             => [new PresenceOf(['message' => '请填写佣金规则名称'])],
            'Fixed'            => [new PresenceOf(['message' => '请填写固定数值']), new Digit(['message' => '固定数值的格式错误'])],
            'Ratio'            => [new PresenceOf(['message' => '请填写百分比']), new Digit(['message' => '百分比的格式错误'])],
            'DistributionOutB' => [new PresenceOf(['message' => '请填写分销分发给医院的百分比']), new Digit(['message' => '分销分发给医院百分比的格式错误'])],
            'DistributionOut'  => [new PresenceOf(['message' => '请填写分销分发给网点的百分比']), new Digit(['message' => '分销分发给网点百分比的格式错误'])],
            'OrganizationId'   => [new PresenceOf(['message' => '请选择机构']), new Digit(['message' => '机构的格式错误'])],
            'Type'             => [new PresenceOf(['message' => '请选择佣金方式']), new Digit(['message' => '佣金方式的格式错误'])],
        ];
        $scenes = [
            self::SCENE_RULE_CREATE           => ['Name', 'Type', 'OrganizationId'],
            self::SCENE_ADMIN_HOSPITAL_CREATE => ['Ratio', 'DistributionOutB'],
            self::SCENE_SUPPLIER_CREATE       => ['Ratio'],
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
    }

    public function beforeCreate()
    {
        //
        $this->DistributionOut = 0;
    }

    public function beforeUpdate()
    {
        $this->DistributionOut = 0;
        $changed = (array)$this->getChangedFields();
        if ($this->getDI()->getShared('session')->get('auth') && $this->getDI()->getShared('session')->get('auth')['OrganizationId'] === null && (in_array('Ratio', $changed, true) || in_array('DistributionOutB', $changed, true) || in_array('DistributionOut', $changed, true))) {
            $this->staffHospitalLog(StaffHospitalLog::UPDATE);
        }
    }

    private function staffHospitalLog($status)
    {
        $staffHospitalLog = new StaffHospitalLog();
        $staffHospitalLog->StaffId = $this->getDI()->getShared('session')->get('auth')['Id'];
        $staffHospitalLog->OrganizationId = $this->OrganizationId;
        $staffHospitalLog->Created = time();
        $staffHospitalLog->Operated = $status;
        $staffHospitalLog->save();
    }
}
