<?php

namespace App\Models;

use App\Libs\combo\Rule;
use Phalcon\Mvc\Model;

class ComboRefund extends Model
{
    //退款申请单状态 1=>待审核 2=>审核通过 3=>审核不通过
    const STATUS_WAIT = 1;
    const STATUS_PASS = 2;
    const STATUS_UNPASS = 3;
    const STATUS_NAME = [1 => '待处理', 2 => '已退款', 3 => '拒绝退款'];

    //来源类型 1=>待分配套餐单  2=>待使用套餐单
    const ReferenceType_Slave = 1;
    const ReferenceType_Patient = 2;

    public $Id;
    public $OrderNumber;
    public $ComboId;
    public $ComboName;
    public $ReferenceType;
    public $ReferenceId;
    public $SellerOrganizationId;
    public $BuyerOrganizationId;
    public $Created;
    public $FinishTime;
    public $Status;
    public $Quantity;
    public $Price;
    public $ApplyReason;
    public $RefuseReason;
    public $Image;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'ComboRefund';
    }

    public function beforeCreate()
    {
        $this->Created = time();
        $this->Status = self::STATUS_WAIT;
    }

    public function afterCreate()
    {
        $this->log();
    }

    public function afterUpdate()
    {
        $this->log();
    }

    public function log()
    {
        $log = new ComboRefundLog();
        $log->ComboRefundId = $this->Id;
        $log->Status = $this->Status;
        $log->save();
    }
}
