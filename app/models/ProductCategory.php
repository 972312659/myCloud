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
use Phalcon\Validation\Validator\StringLength;

class ProductCategory extends Model
{
    const TOP_PID = 0;
    const DRUG = 1;//数据库药品

    public $Id;

    public $Name;

    public $Pid;

    public function initialize()
    {

    }

    public function getSource()
    {
        return 'ProductCategory';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('Name', [
            new PresenceOf(['message' => '商品种类不能为空']),
            new StringLength(["min" => 0, "max" => 50, "messageMaximum" => '商品种类不能超过50个字符']),
        ]);
        return $this->validate($validate);
    }
}