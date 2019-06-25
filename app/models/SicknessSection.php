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
use Phalcon\Validation\Validator\Uniqueness;

class SicknessSection extends Model
{
    public $Id;

    public $Name;

    public $Pid;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'SicknessSection';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->add('Name',
            new Uniqueness(['message' => '该科室已存在'])
        );
        return $this->validate($validate);
    }
}