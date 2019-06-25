<?php

namespace App\Models;

use App\Validators\Mobile;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Uniqueness;

class WithdrawalsFee extends Model
{

    //唯一一条数据
    const ID = 1;

    public $Id;

    public $Fee;

    public $Remark;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'WithdrawalsFee';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules('Fee', [
            new PresenceOf(['message' => '费率不能为空']),
        ]);
        return $this->validate($validator);
    }

    /**
     * @param int $amount
     * @param int $fee
     * @return int 提现金额+手续费
     */
    public static function moneyReduce(int $amount, int $fee): int
    {
        return $amount + ceil($amount * $fee / 1000);
    }
}