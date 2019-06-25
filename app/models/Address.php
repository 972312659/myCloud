<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2018/6/5
 * Time: 11:45
 */

namespace App\Models;

use Phalcon\Mvc\Model;
use App\Validators\Mobile;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Digit;

class Address extends Model
{
    //是否为默认收货地址 0=>no 1=>yes
    const DEFAULT_NO = 0;
    const DEFAULT_YES = 1;

    public $Id;

    public $Postcode;

    public $ProvinceId;

    public $CityId;

    public $AreaId;

    public $Address;

    public $OrganizationId;

    public $Phone;

    public $Contacts;

    public $Default;


    public function getSource()
    {
        return 'Address';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules('ProvinceId', [
            new PresenceOf(['message' => '省份不能为空']),
            new Digit(["message" => '省份格式错误']),
        ]);
        $validator->rules('CityId', [
            new PresenceOf(['message' => '市不能为空']),
            new Digit(["message" => '市格式错误']),
        ]);
        $validator->rules('AreaId', [
            new PresenceOf(['message' => '区域不能为空']),
            new Digit(["message" => '区域格式错误']),
        ]);
        $validator->rules('Address', [
            new PresenceOf(['message' => '详细地址不能为空']),
        ]);
        $validator->rules('Default', [
            new Digit(["message" => '默认地址格式错误']),
        ]);
        $validator->rules('Phone', [
            new Mobile(["message" => '电话号码必须是11位手机号']),
        ]);
        $validator->rules('Contacts', [
            new PresenceOf(["message" => '联系人不能为空']),
        ]);
        return $this->validate($validator);
    }
}