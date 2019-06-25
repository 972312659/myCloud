<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/12/4
 * Time: 下午3:47
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class InteriorTradeLog extends Model
{
    public $Id;

    public $InteriorTradeId;

    public $UserId;

    public $UserName;

    public $Status;

    public $LogTime;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'InteriorTradeLog';
    }
}