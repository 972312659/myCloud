<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/12/4
 * Time: 下午3:47
 */

namespace App\Models;

use App\Enums\Status;
use App\Exceptions\ParamException;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\StringLength;
use Phalcon\Validation\Validator\Digit;

class InteriorTrade extends Model
{
    //审核状态 1=>未审核  2=>审核通过 3=>审核未通过 4=>出纳已付款 5=>取消
    const STATUS_WAIT = 1;
    const STATUS_PASS = 2;
    const STATUS_UNPASS = 3;
    const STATUS_PREPAID = 4;
    const STATUS_CANCEL = 5;
    const STATUS_NAME = [1 => '待审核', 2 => '审核通过', 3 => '审核未通过', 4 => '出纳已付款', self::STATUS_CANCEL => '取消申请'];

    //类型 1=>转诊 2=>内部转账 4=>退款
    const STYLE_TRANSFER = 1;
    const STYLE_ACCOUNTS = 2;
    const STYLE_PRODUCT = 3;
    const STYLE_REFUND = 4;
    const STYLE_NAME = [1 => '首诊服务费', 2 => '转账', 3 => '商城订单', 4 => '商城退款单'];

    public $Id;

    public $SendOrganizationId;

    public $AcceptOrganizationId;

    public $SendOrganizationName;

    public $AcceptOrganizationName;

    public $Amount;

    public $Message;

    public $Remark;

    public $Explain;

    public $TransferId;

    public $Style;

    public $Status;

    public $Created;

    public $ShareCloud;

    public $Total;

    public function initialize()
    {
        $this->keepSnapshots(true);
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'InteriorTrade';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('Explain', [
            new StringLength(["min" => 0, "max" => 100, "messageMaximum" => '理由不能超过100个字符']),
        ]);
        $validate->rules('Remark', [
            new StringLength(["min" => 0, "max" => 100, "messageMaximum" => '备注不能超过100个字符']),
        ]);
        $validate->rules('Total', [
            new Digit(["message" => "总付款必须是数字"]),
        ]);
        return $this->validate($validate);
    }

    public function afterCreate()
    {
        $this->log();
    }

    public function beforeUpdate()
    {
        $changed = (array)$this->getChangedFields();
        if (count($changed)) {
            if (in_array($this->Status, [self::STATUS_PASS, self::STATUS_UNPASS])) {
                if ($this->Style == self::STYLE_TRANSFER) {
                    $auth = $this->getDI()->getShared('session')->get('auth');
                    /** @var InteriorTradeAndTransfer $interiorTradeAndTransfer */
                    $interiorTradeAndTransfer = InteriorTradeAndTransfer::findFirst(sprintf('InteriorTradeId=%d', $this->Id));

                    //业务经理奖励
                    /** @var SalesmanBonus $salesmanBonus */
                    $salesmanBonus = SalesmanBonus::findFirst([
                        'conditions' => 'OrganizationId=?0 and UserId=?1 and ReferenceType=?2 and ReferenceId=?3',
                        'bind'       => [$auth['OrganizationId'], $auth['Id'], SalesmanBonus::ReferenceType_Transfer, $interiorTradeAndTransfer->TransferId],
                    ]);
                    if ($salesmanBonus) {
                        $exp = new ParamException(Status::BadRequest);
                        try {
                            $salesmanBonus->Status = $this->Status == self::STATUS_PASS ? SalesmanBonus::STATUS_CASHIER : SalesmanBonus::STATUS_FINANCE_REFUSE;
                            if ($salesmanBonus->save() === false) {
                                $exp->loadFromModel($salesmanBonus);
                                throw $exp;
                            }
                        } catch (ParamException $e) {
                            throw $e;
                        }

                    }
                }
            }
        }
    }

    public function afterUpdate()
    {
        $this->log();
    }

    public function log()
    {
        $auth = $this->getDI()->getShared('session')->get('auth');
        $log = new InteriorTradeLog();
        $log->InteriorTradeId = $this->Id;
        $log->UserId = $auth['Id'];
        $log->UserName = $auth['Name'];
        $log->Status = $this->Status;
        $log->LogTime = time();
        $log->save();
    }
}
