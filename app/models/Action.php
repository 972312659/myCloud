<?php

namespace App\Models;

use Phalcon\Mvc\Model;

/**
 * Class Action
 * 此类修改请务必同时修改tool相应的model
 * @package App\Models
 */
class Action extends Model
{
    const Anonymous = 0;

    const Authorize = 1;

    public $Id;

    public $Controller;

    public $Action;

    public $FeatureId;

    public $Type;

    public $Discard;

    public function getSource()
    {
        return 'Action';
    }
}