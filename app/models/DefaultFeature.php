<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class DefaultFeature extends Model
{
    public $Type;

    public $FeatureId;

    public function getSource()
    {
        return 'DefaultFeature';
    }
}