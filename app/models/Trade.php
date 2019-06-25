<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/8/10
 * Time: 14:14
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class Trade extends Model
{
    // 网关类型
    const GATEWAY_ALIPAY = 1;
    const GATEWAY_WXPAY = 2;

    // 状态类型
    const STATUS_BLANK = 0;
    const STATUS_PENDING = 1;
    const STATUS_COMPLETE = 2;
    const STATUS_CLOSE = 3;
    const STATUS_NAME = [0 => '', 1 => '待交易', 2 => '交易完成', 3 => '交易关闭'];

    // 交易类型
    const TYPE_CHARGE = 1;
    const TYPE_ENCASH = 2;

    //财务审核状态
    const AUDIT_NO = 0;
    const AUDIT_PASS = 1;
    const AUDIT_UNPASS = 2;
    const AUDIT_NAME = [0 => '财务未审核', 1 => '已审核通过', 2 => '审核未通过'];

    //属于机构还是个人 1=>机构 2=>个人
    const Belong_Organization = 1;
    const Belong_Personal = 2;
    const Belong_Name = [1 => '机构', 2 => '个人'];

    public $Id;

    public $Gateway;

    public $Account;

    public $SerialNumber;

    public $Name;

    public $Bank;

    public $Amount;

    public $Type;

    public $OrganizationId;

    public $UserId;

    public $HospitalId;

    public $Status;

    public $Created;

    public $Updated;

    public $Audit;

    public $Fake;

    public $Belong;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('OrganizationId', Organization::class, 'Id', ['alias' => 'Organization']);
        $this->hasMany('Id', TradeLog::class, 'TradeId', ['alias' => 'TradeLog']);
    }

    public function getSource()
    {
        return 'Trade';
    }

    public function beforeUpdate()
    {
        $changed = (array)$this->getChangedFields();
        if ($this->getDI()->getShared('session')->get('auth') && $this->getDI()->getShared('session')->get('auth')['OrganizationId'] === null && in_array('Status', $changed, true)) {
            if ($this->Status == self::STATUS_COMPLETE) {
                //员工操作记录
                $log = new StaffTradeLog();
                $log->TradeId = $this->Id;
                $log->StaffId = $this->getDI()->getShared('session')->get('auth')['Id'];
                $log->StatusBefore = self::STATUS_PENDING;
                $log->StatusAfter = self::STATUS_COMPLETE;
                $log->Created = time();
                $log->Finance = StaffTradeLog::FINANCE_CASHIER;
                $log->save();
            }
        }
    }
}