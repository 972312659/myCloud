<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/6
 * Time: 2:33 PM
 */

namespace App\Models;


use Phalcon\Mvc\Model;

class IllnessAndFileCreateAttribute extends Model
{
    public $IllnessId;

    public $FileCreateAttributeId;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'IllnessAndFileCreateAttribute';
    }
}