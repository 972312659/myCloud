<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class OrganizationType extends Model
{
    public $Id;

    public $Name;

    public function getSource()
    {
        return 'OrganizationType';
    }
}