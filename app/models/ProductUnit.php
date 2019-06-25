<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/30
 * Time: 下午2:15
 */

namespace App\Models;

use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Libs\Rule;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;

class ProductUnit extends Model
{
    //0=>不默认显示 1=>默认显示
    const IS_DEFAULT_NO = 0;
    const IS_DEFAULT_YES = 1;

    public $Id;

    public $ProductId;

    public $Number;

    public $Price;

    public $PriceForSlave;

    public $Postage;

    public $IsDefault;

    public function initialize()
    {
        $this->belongsTo('ProductId', Product::class, 'Id', ['alias' => 'Product']);
        $this->hasOne('Id', ProductUnitStatus::class, 'ProductUnitId', ['alias' => 'Status']);
        $this->hasMany('Id', ProductUnitPicture::class, 'ProductUnitId', ['alias' => 'Images']);
    }

    public function getSource()
    {
        return 'ProductUnit';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rule('ProductId', new Digit(['message' => '商品格式错误11']));
        $validate->rule('Price', new Digit(['message' => '价格格式错误']));
        $validate->rule('Postage', new Digit(['message' => '运费格式错误']));
        return $this->validate($validate);
    }

    public function beforeCreate()
    {
        $this->Number = Rule::productUnitNumber();
    }

    public function getPriceByOrganization(Organization $organization)
    {
        if ($organization->IsMain === Organization::ISMAIN_HOSPITAL) {
            return $this->Price;
        } elseif ($organization->IsMain == Organization::ISMAIN_SLAVE) {
            return $this->PriceForSlave;
        } else {
            throw new LogicException('不卖给你', Status::InternalServerError);
        }
    }
}
