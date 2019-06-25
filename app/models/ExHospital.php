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
use Phalcon\Validation\Validator\Url;

class ExHospital extends Model
{
    public $Id;

    public $Name;

    public $Address;

    public $Image;

    public $Updated;

    public $Sc114Id;

    public $Sc114Code;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'ExHospital';
    }

    public function validation()
    {
        $validation = new Validation();
        $validation->rules('Name', [new PresenceOf(['message' => '请填写医院名称'])]);
        $validation->rules('Address', [new PresenceOf(['message' => '请填写医院地址'])]);
        $validation->rules('Image', [new Url(['message' => '医院logo必须为url', 'allowEmpty' => true])]);
        return $this->validate($validation);
    }

    public function beforeUpdate()
    {
        $this->Updated = time();
    }
}