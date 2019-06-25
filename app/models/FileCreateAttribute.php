<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/6
 * Time: 2:33 PM
 */

namespace App\Models;


use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\StringLength;

class FileCreateAttribute extends Model
{
    public $Id;

    public $Name;

    public $MaxLength;

    public $Created;

    public $IsRequired;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'FileCreateAttribute';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules('Name', [
            new PresenceOf(['message' => '名称不能为空']),
            new StringLength(["min" => 0, "max" => 100, "messageMaximum" => '最长不超过100']),
        ]);
        return $this->validate($validator);
    }

    public function beforeCreate()
    {
        self::verify();
    }

    public function beforeUpdate()
    {
        self::verify();
    }

    protected function verify()
    {
        if (!is_numeric($this->MaxLength)) {
            $this->MaxLength = null;
        }
    }
}