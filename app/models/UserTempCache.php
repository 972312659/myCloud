<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/8/25
 * Time: 上午11:34
 */

namespace App\Models;


use Phalcon\Mvc\Model;

class UserTempCache extends Model
{
    public $Id;

    public $Phone;

    public $MerchantCode;

    public $Content;

    public $Message;

    public $Code;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'UserTempCache';
    }
}