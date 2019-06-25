<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\PresenceOf;

class PadVersion extends Model
{
    public $Id;

    public $VersionName;

    public $VersionCode;

    public $ApkUrl;

    public $ApkName;

    public $Created;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'PadVersion';
    }
}
