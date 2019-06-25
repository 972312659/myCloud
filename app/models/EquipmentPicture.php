<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/9/14
 * Time: 17:03
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class EquipmentPicture extends Model
{
    public $Id;

    public $OrganizationId;

    public $EquipmentId;

    public $Image;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'EquipmentPicture';
    }
}