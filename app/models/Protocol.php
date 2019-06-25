<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/11/16
 * Time: 下午3:18
 */

namespace App\Models;


use Phalcon\Mvc\Model;

class Protocol extends Model
{
    const NAME_MEDICAL_ALLIANCE_COOPERATION_AGREEMENT_Id = 1;

    public $Id;

    public $Name;

    public $Content;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'Protocol';
    }
}