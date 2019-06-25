<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/4/2
 * Time: 3:41 PM
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class SalesmanBonus extends Model
{

    //规则方式 0=>为百分比 1=>为固定金额
    const IsFixed_No = 0;
    const IsFixed_Yes = 1;
    const IsFixedName = [0 => '按比例', 1 => '固定金额'];

    //奖励类型
    const ReferenceType_Transfer = 1;
    const ReferenceType_Name = [1 => '转诊'];

    //财务审核状态 1=>财务待审核 2=>审核不通过 3=>出纳待结算  4=>出纳已结算
    const STATUS_FINANCE = 1;
    const STATUS_FINANCE_REFUSE = 2;
    const STATUS_CASHIER = 3;
    const STATUS_CASHIER_PAYMENT = 4;

    public $Id;

    public $UserId;

    public $OrganizationId;

    public $ReferenceType;

    public $ReferenceId;

    public $Describe;

    public $Amount;

    public $IsFixed;

    public $Value;

    public $Bonus;

    public $Created;

    public $Status;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'SalesmanBonus';
    }

    public function beforeCreate()
    {
        $this->Created = time();
    }

    public static function describe($referenceType)
    {
        $describe = '';
        switch ($referenceType) {
            case self::ReferenceType_Transfer:
                $describe = "网点（%s）转诊结算";
                break;

        }
        return $describe;
    }

}