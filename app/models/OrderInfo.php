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
use App\Validators\Mobile;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\StringLength;

class OrderInfo extends Model
{
    public $Id;

    public $ProvinceId;

    public $CityId;

    public $AreaId;

    public $Address;

    public $Phone;

    public $Contacts;

    public function initialize()
    {
        $this->hasOne('ProvinceId', Location::class, 'Id', ['alias' => 'Province']);
        $this->hasOne('CityId', Location::class, 'Id', ['alias' => 'City']);
        $this->hasOne('AreaId', Location::class, 'Id', ['alias' => 'Area']);
    }

    public function getSource()
    {
        return 'OrderInfo';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('ProvinceId', [
            new PresenceOf(['message' => '省份不能为空']),
            new Digit(['message' => '省份格式错误']),
        ]);
        $validate->rules('CityId', [
            new PresenceOf(['message' => '城市不能为空']),
            new Digit(['message' => '城市格式错误']),
        ]);
        $validate->rules('AreaId', [
            new PresenceOf(['message' => '区域不能为空']),
            new Digit(['message' => '区域格式错误']),
        ]);
        /*$validate->rules('Postcode', [
            new PresenceOf(['message' => '邮编不能为空']),
            new StringLength(["min" => 6, "max" => 6, "messageMaximum" => '邮编格式错误']),
        ]);*/
        $validate->rules('Address', [
            new PresenceOf(['message' => '详细地址不能为空']),
            new StringLength(["min" => 0, "max" => 100, "messageMaximum" => '详细地址不能超过100个字符']),
        ]);
        $validate->rules('Phone', [
            new PresenceOf(['message' => '手机号不能为空']),
            new Mobile(['message' => '请输入正确的手机号']),
        ]);
        return $this->validate($validate);
    }
}
