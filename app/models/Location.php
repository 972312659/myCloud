<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class Location extends Model
{
    use ValidationTrait;

    public $Id;

    public $Name;

    public $PId;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'Location';
    }
}
