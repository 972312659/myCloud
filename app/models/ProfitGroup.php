<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/8/17
 * Time: 上午9:37
 */

namespace App\Models;

use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\StringLength;

use Phalcon\Mvc\Model;

class ProfitGroup extends Model
{
    public $Id;

    public $Name;

    public $OrganizationId;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'ProfitGroup';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('Name', [
            new PresenceOf(['message' => '名字不能为空']),
            new StringLength(["min" => 0, "max" => 10, "messageMaximum" => '最长不超过10']),
        ]);
        return $this->validate($validate);
    }
}