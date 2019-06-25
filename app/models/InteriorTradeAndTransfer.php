<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/12/4
 * Time: 下午3:47
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class InteriorTradeAndTransfer extends Model
{
    public $InteriorTradeId;

    public $TransferId;

    public $Amount;

    public $ShareCloud;


    public function initialize()
    {
    }

    public function getSource()
    {
        return 'InteriorTradeAndTransfer';
    }
}