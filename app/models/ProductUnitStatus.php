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
use Phalcon\Validation\Validator\Digit;

class ProductUnitStatus extends Model
{
    //上架状态 0=>下架 1=>上架
    const STATUS_OFF = 0;
    const STATUS_ON = 1;

    public $ProductUnitId;

    public $Status;

    public $Stock;

    public $WarningLine;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'ProductUnitStatus';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rule('WarningLine', new Digit(['message' => '警戒值格式错误']));
        return $this->validate($validate);
    }

    public function beforeCreate()
    {
        $this->Status = self::STATUS_OFF;
    }
}