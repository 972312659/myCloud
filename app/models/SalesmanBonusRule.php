<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/4/2
 * Time: 3:41 PM
 */

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Between;


class SalesmanBonusRule extends Model
{

    //0=>为百分比 1=>为固定金额
    const IsFixed_No = 0;
    const IsFixed_Yes = 1;
    const IsFixedName = [0 => '按比例', 1 => '固定金额'];

    //奖励类型 1=>对应结算完成转诊单患者花销金额进行奖励
    const Type_TransferCost = 1;

    public $Id;

    public $OrganizationId;

    public $UserId;

    public $IsFixed;

    public $Value;

    public $Type;

    public $Created;


    public function initialize()
    {
        $this->keepSnapshots(true);
    }

    public function getSource()
    {
        return 'SalesmanBonusRule';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('IsFixed', [
            new PresenceOf(['message' => '奖励方式不能为空']),
            new Digit(['message' => '奖励方式的格式错误']),
        ]);
        $validate->rules('Value', [
            new Digit(['message' => '奖励数值格式错误']),
            $this->IsFixed == self::IsFixed_Yes ?
                new Between(['minimum' => 0, 'maximum' => 10000000, 'message' => '固定金额不超过十万']) :
                new Between(['minimum' => 0, 'maximum' => 10000, 'message' => '比例不能高于100%']),
        ]);
        return $this->validate($validate);
    }

    public function beforeCreate()
    {
        $this->Created = time();
    }

    public function afterCreate()
    {
        $this->log('创建奖励方案，' . ($this->IsFixed == self::IsFixed_Yes ? ('固定金额：' . $this->Value / 100) : ('按比例：' . ($this->Value / 100) . '%')));
    }

    public function beforeUpdate()
    {
        $changed = (array)$this->getChangedFields();
        if (count($changed)) {
            $describe = '';
            if (in_array('IsFixed', $changed)) {
                $describe = $this->IsFixed == self::IsFixed_Yes ? '按比例变为固定金额设置' : '固定金额变为按比例设置';
            }
            if (in_array('Value', $changed)) {
                if ($describe) {
                    $describe .= $this->IsFixed == self::IsFixed_Yes ? '，固定金额变为' . ($this->Value / 100) : '，比例数值变为' . ($this->Value / 100) . '%';
                } else {
                    $describe .= $this->IsFixed == self::IsFixed_Yes ? '固定金额变为' . ($this->Value / 100) : '比例数值变为' . ($this->Value / 100) . '%';
                }
            }
            $this->log($describe);
        }
    }

    public function log(string $describe)
    {
        /** @var SalesmanBonusRuleLog $log */
        $log = new SalesmanBonusRuleLog();
        $log->SalesmanBonusRuleId = $this->Id;
        $log->Describe = $describe;
        $log->save();
    }
}