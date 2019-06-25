<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/8/25
 * Time: 上午11:34
 */

namespace App\Models;


use App\Validators\IDCardNo;
use App\Validators\Lat;
use App\Validators\Lng;
use Phalcon\Mvc\Model;
use App\Validators\Mobile;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\StringLength;

class SupplierApply extends Model
{
    //申请状态 0=>待处理 1=>申请审核已通过，供应商已开通 2=>申请审核未通过，供应商未开通
    const STATUS_WAIT = 0;
    const STATUS_PASS = 1;
    const STATUS_UNPASS = 2;
    const STATUS_NAME = [0 => '待处理', 1 => '申请审核已通过，供应商已开通', 2 => '申请审核未通过，供应商未开通'];

    public $Id;

    public $HospitalId;

    public $Name;

    public $LevelId;

    public $Type;

    public $Contact;

    public $ContactTel;

    public $IDnumber;

    public $ProvinceId;

    public $CityId;

    public $AreaId;

    public $Address;

    public $Lng;

    public $Lat;

    public $Phone;

    public $Password;

    public $Ratio;

    public $DistributionOut;

    public $Created;

    public $Updated;

    public $Explain;

    public $Status;

    public $SalesmanId;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('HospitalId', Organization::class, 'Id', ['alias' => 'Hospital']);
    }

    public function getSource()
    {
        return 'SupplierApply';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->add(['HospitalId', 'LevelId', 'Type', 'SalesmanId'],
            new Digit([
                'message' => [
                    'HospitalId' => 'HospitalId必须为整形数字',
                    'LevelId'    => 'LevelId必须为整形数字',
                    'Type'       => 'Type必须为整形数字',
                    'SalesmanId' => 'SalesmanId必须为整形数字',
                ],
            ])
        );
        $validator->add([
            'Name', 'LevelId', 'Type', 'Contact', 'ContactTel', 'Lat',
            'IDnumber', 'ProvinceId', 'CityId', 'AreaId', 'Address', 'Lng',
            'Phone', 'Password', 'Ratio', 'SalesmanId',
        ],
            new PresenceOf([
                'message' => [
                    'Name'       => '供应商名字不能为空',
                    'LevelId'    => '供应商等级不能为空',
                    'Type'       => '供应商性质不能为空',
                    'Contact'    => '供应商联系人不能为空',
                    'ContactTel' => '供应商联系人电话不能为空',
                    'Lat'        => '纬度不能为空',
                    'IDnumber'   => '身份证号码不能为空',
                    'ProvinceId' => '省份不能为空',
                    'CityId'     => '城市不能为空',
                    'AreaId'     => '地区不能为空',
                    'Address'    => '具体地址不能为空',
                    'Lng'        => '经度不能为空',
                    'Phone'      => '账号不能为空',
                    'Password'   => '密码不能为空',
                    'Ratio'      => '医院收取手续费比例不能为空',
                    'SalesmanId' => '营销人员不能为空',
                ],
            ])
        );
        $validator->add('Phone', new Mobile(['message' => '请填写有效的手机号码']));
        $validator->add('Name', new StringLength(["min" => 0, "max" => 50, "messageMaximum" => '供应商名字不超过50个字']));
        $validator->add(['Lng'], new Lng(["message" => "经度格式错误"]));
        $validator->add(['Lat'], new Lat(["message" => "纬度格式错误"]));
        $validator->add(['IDnumber'], new IDCardNo(["message" => "身份证号码错误"]));

        return $this->validate($validator);
    }

    public function beforeCreate()
    {
        $this->Created = time();
        $this->Status = self::STATUS_WAIT;
        $this->DistributionOut = 0;
    }

    public function beforeUpdate()
    {
        $this->Updated = time();
    }
}