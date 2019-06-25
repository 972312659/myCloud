<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/11/26
 * Time: 上午11:01
 */

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\StringLength;

class OfflinePayImage extends Model
{
    public $Id;

    public $OfflinePayId;

    public $Image;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'OfflinePayImage';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules('Image', [
            new PresenceOf(['message' => '图片不能为空']),
            new StringLength(["min" => 0, "max" => 255, "messageMaximum" => '最长不超过255']),
        ]);
        return $this->validate($validator);
    }
}