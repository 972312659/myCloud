<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/8/25
 * Time: 上午11:34
 */

namespace App\Models;


use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\StringLength;

class DoctorRecommendedReason extends Model
{
    public $Id;

    public $OrganizationId;

    public $UserId;

    public $Content;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'DoctorRecommendedReason';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('Content', [
            new PresenceOf(['message' => '推荐理由不能为空']),
            new StringLength(["min" => 0, "max" => 50, "messageMaximum" => '最长不超过50']),
        ]);
        return $this->validate($validate);
    }
}