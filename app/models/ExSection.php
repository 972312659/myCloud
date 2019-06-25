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

class ExSection extends Model
{
    public $Id;

    public $ParentId;

    public $Name;

    public $Updated;

    public $Sc114Id;

    public $Sc114Code;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'ExSection';
    }

    public function validation()
    {
        $validation = new Validation();
        $validation->rules('Name', [new PresenceOf(['message' => '请填写科室名称'])]);
        return $this->validate($validation);
    }

    public function beforeUpdate()
    {
        $this->Updated = time();
    }
}