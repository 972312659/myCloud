<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Digit;

class ComboAndOrder extends Model
{
    public $ComboOrderId;

    public $ComboId;

    public $ComboOrderBatchId;

    public $Name;

    public $Price;

    public $Way;

    public $Amount;

    public $Image;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'ComboAndOrder';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rule('Name',
            new PresenceOf(['message' => '套餐名不能为空'])
        );
        $validator->rule('Price',
            new PresenceOf(['message' => '套餐价格不能为空'])
        );
        $validator->add(['ComboOrderId', 'ComboId', 'ComboOrderBatchId'],
            new Digit([
                'message' => [
                    'ComboOrderId'      => '订单必须为整形数字',
                    'ComboId'           => '套餐必须为整形数字',
                    'ComboOrderBatchId' => '网点批量采购套餐必须为整形数字',
                ],
            ])
        );
        return $this->validate($validator);
    }

}
