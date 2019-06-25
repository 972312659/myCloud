<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/11/16
 * Time: 下午3:18
 */

namespace App\Models;


use Phalcon\Mvc\Model;

class OrganizationAndProtocol extends Model
{
    public $OrganizationId;

    public $ProtocolId;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'OrganizationAndProtocol';
    }
}