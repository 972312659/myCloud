<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/30
 * Time: 下午2:15
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class StaffTradeLog extends Model
{
    const FINANCE_VERIFY = 1;   //审核员
    const FINANCE_CASHIER = 2;  //出纳员

    //注意：状态是指的Trade表中的Audit

    public $Id;

    public $StaffId;

    public $TradeId;

    public $StatusBefore;

    public $StatusAfter;

    public $Created;

    public $Finance;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'StaffTradeLog';
    }
}
