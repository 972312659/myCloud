<?php

namespace App\Libs\fake\models;

use Phalcon\Mvc\Model;

class User extends Model
{
    public $id;

    public $name;

    public function initialize()
    {
        $this->setSource('User');
    }
}
