<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class ExpressCompany extends Model
{
    public $Id;

    public $Name;

    public $Com;

    public $Image;

    public $Type;

    public function getSource()
    {
        return 'ExpressCompany';
    }
}
