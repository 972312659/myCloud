<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/20
 * Time: 12:10 PM
 */

namespace App\Libs\login;


use App\Models\Organization;

class Expire
{
    public static function judgePast(Organization $organization)
    {
        $result = false;
        $today = date('Y-m-d');
        if ($organization->IsMain == Organization::ISMAIN_HOSPITAL) {
            if ($organization->Expire < $today) {
                $result = true;
            }
        }
        return $result;
    }
}