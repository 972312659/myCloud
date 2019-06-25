<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/8/17
 * Time: 上午9:37
 */

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\Between;

class ProfitRule extends Model
{
    //0=>为百分比 1=>为固定金额
    const IsFixed_No = 0;
    const IsFixed_Yes = 1;
    const IsFixedName = [0 => '按比例', 1 => '固定金额'];

    //门诊或者住院 1=>门诊 2=>住院 null=>门诊或住院都可
    const OutpatientOrInpatient_Out = 1;
    const OutpatientOrInpatient_In = 2;
    const OutpatientOrInpatient_Both = null;
    const OutpatientOrInpatientName = [1 => '门诊', 2 => '住院', null => '不限'];

    public $Id;

    public $MinAmount;

    public $MaxAmount;

    public $SectionId;

    public $GroupId;

    public $OrganizationId;

    public $BeginTime;

    public $EndTime;

    public $IsFixed;

    public $Value;

    public $Priority;

    public $OutpatientOrInpatient;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'ProfitRule';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('MinAmount', [
            new PresenceOf(['message' => '最小金额不能为空']),
            new Between(['minimum' => 0, 'maximum' => 1000000000, 'message' => '最小金额最大不超过10000000']),
        ]);
        $validate->rules('MaxAmount', [
            new Between(['minimum' => 0, 'maximum' => 1000000000, 'message' => '最大金额最大不超过10000000']),
        ]);
        $validate->rules('IsFixed', [
            new PresenceOf(['message' => '方式不能为空']),
            new Digit(['message' => '佣金方式格式错误']),
        ]);
        $validate->rules('BeginTime', [
            new Digit(['message' => '开始时间格式错误']),
        ]);
        $validate->rules('Value', [
            new PresenceOf(['message' => '比例金额不能为空']),
            new Digit(['message' => '比例金额格式错误']),
            $this->IsFixed == self::IsFixed_Yes ?
                new Between(['minimum' => 0, 'maximum' => 1000000000, 'message' => '佣金金额最大不超过10000000']) :
                new Between(['minimum' => 0, 'maximum' => 100, 'message' => '佣金比例最大不超过100%']),
        ]);
        $validate->rules('Priority', [
            new PresenceOf(['message' => '排序不能为空，升序排列，最小为0.01，最大为99']),
            new Between(['minimum' => 0.01, 'maximum' => 99.00, 'message' => '排序介于0.01和99之间']),
        ]);
        return $this->validate($validate);
    }
}