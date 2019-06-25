<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/30
 * Time: 下午2:15
 */

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Digit;

class ServiceCharge extends Model
{
    public $OrganizationId;

    public $MedicineOrderFee;

    public function initialize()
    {

    }

    public function getSource()
    {
        return 'ServiceCharge';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('MedicineOrderFee', [
            new PresenceOf(['message' => '平台手续费不能为空']),
            new Digit(['message' => '平台手续费格式错误']),
        ]);
        return $this->validate($validate);
    }
}