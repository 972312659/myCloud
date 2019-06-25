<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/8/25
 * Time: 上午11:34
 */

namespace App\Models;


use App\Libs\Sphinx;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Uniqueness;

class Sickness extends Model
{
    public $Id;

    public $Name;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'Sickness';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->add('Name',
            new Uniqueness(['message' => '该疾病已存在'])
        );
        return $this->validate($validate);
    }

    public function afterCreate()
    {
        self::sphinx();
    }

    public function afterUpdate()
    {
        self::sphinx();
    }

    public function afterDelete()
    {
        $sphinx = new Sphinx($this->getDI()->getShared('sphinx'), 'sickness');
        $sphinx->delete($this->Id);
    }

    protected function sphinx()
    {
        $sphinx = new Sphinx($this->getDI()->getShared('sphinx'), 'sickness');
        $sphinx_data = ['id' => $this->Id, 'name' => $this->Name, 'organizations' => []];
        $sphinx->save($sphinx_data);
    }
}