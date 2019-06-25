<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/8/3
 * Time: 下午4:11
 */

namespace App\Enums;


class WebrtcName
{
    const App = 'IM_App_%s_%s';                        //App端(所有app)
    const Pad = 'IM_Pad_%s_%s';                        //PAD端
    const Slave_Web = 'IM_SlaveWeb_%s_%s';             //网点Web端
    const Web_Doctor = 'IM_Doctor_%s_%s';              //医院web医生端

    public static function getHospitalDoctor($hospitalId, $doctorId)
    {
        return sprintf(self::Web_Doctor, $hospitalId, $doctorId);
    }

    public static function getSlaveWeb($organizationId, $userId)
    {
        return sprintf(self::Pad, $organizationId, $userId);
    }

    public static function getApp($organizationId, $userId)
    {
        return sprintf(self::App, $organizationId, $userId);
    }

    public static function getPad($organizationId, $userId)
    {
        return sprintf(self::Pad, $organizationId, $userId);
    }

}

