<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2018/6/5
 * Time: 11:45
 */

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\StringLength;

class News extends Model
{
    //是否上架 0=>下架 1=>上架
    const STATUS_OFF = 0;
    const STATUS_ON = 1;

    public $Id;

    public $Title;

    public $Html;

    public $Created;

    public $Updated;

    public $Status;

    public function getSource()
    {
        return 'News';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules('Title', [
            new PresenceOf(['message' => '标题不能为空']),
            new StringLength(["min" => 0, "max" => 50, "messageMaximum" => '标题不超过50个字']),
        ]);
        return $this->validate($validator);
    }
}