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
use Phalcon\Validation\Validator\Between;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Url;

class ExDoctor extends Model
{
    public $Id;

    public $ParentId;

    public $Name;

    public $Degree;

    public $Image;

    public $Speciality;

    public $Expert;

    public $Detail;

    public $Updated;

    public $Sc114Id;

    public $Sc114Code;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'ExDoctor';
    }

    public function validation()
    {
        $validation = new Validation();
        $validation->rules('Name', [new PresenceOf(['message' => '请填写医生名称'])]);
        $validation->rules('Degree', [new PresenceOf(['message' => '请填写医生职位'])]);
        $validation->rules('Image', [new Url(['message' => '头像必须为url', 'allowEmpty' => true])]);
        $validation->rules('Speciality', [new Between(['minimum' => 0, 'maximum' => 100, 'message' => '专业不超过100个字'])]);
        $validation->rules('Expert', [new Between(['minimum' => 0, 'maximum' => 1000, 'message' => '擅长不超过1000个字'])]);
        $validation->rules('Detail', [new Between(['minimum' => 0, 'maximum' => 1000, 'message' => '简介不超过1000个字'])]);
        return $this->validate($validation);
    }

    public function beforeUpdate()
    {
        $this->Updated = time();
    }
}