<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;

class Category extends Model
{
    //默认
    const NOTICE = 1;
    const PEACH = 2;

    use ValidationTrait;

    public $Id;

    public $Name;

    public $Intro;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->hasMany('Id', Article::class, 'CategoryId', ['alias' => 'Articles']);
    }

    public function getSource()
    {
        return 'Category';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rule('Name',
            new PresenceOf(['message' => '分类名不能为空'])
        );
        return $this->validate($validator);
    }
}
