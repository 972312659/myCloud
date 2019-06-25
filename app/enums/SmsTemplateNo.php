<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/5/29
 * Time: 10:26 AM
 */

namespace App\Enums;


class SmsTemplateNo
{
    /**
     * 创建网点，发送激活码
     */
    const CREATE_SLAVE = 'T_CLINIC_TRANSFER_ACTIVATECODE';
    /**
     * 激活网点成功，发送消息
     */
    const CREATE_SLAVE_SUCCESS = 'T_CLINIC_TRANSFER_CREATEACCOUNT';


    /**
     * 兼容模板及之前内容
     */
    public static function getTemplateParam(String $str, int $code)
    {
        $templateParam = json_decode($str, true);
        if (!$templateParam) {
            $templateParam = [];
            $arr = explode('，', $str);
            switch ($code) {
                case 1:
                    //1【云转诊】尊敬的用户，您的账号已开通，商户号为5110025，登录账号为17602849402，密码849402，为了您的账户安全，请您尽快登录并修改密码，如有疑问请致电028-69912686。
                    $templateParam = [
                        'merchantno'    => str_replace('商户号为', '', $arr[2]),
                        'loginname'     => str_replace('登录账号为', '', $arr[3]),
                        'loginpass'     => str_replace('密码', '', $arr[4]),
                        'hospitalphone' => str_replace('。', '', str_replace('如有疑问请致电', '', $arr[7])),
                    ];
                    break;
                case 2:
                    //2 【云转诊】尊敬的用户， 包日哈测试医院3-勿动为您开放了云转诊平台的账号，商户号为5110033，登录账号为19012222222，密码222222，为了您的账户安全，如果是默认密码请您尽快登录并修改密码，如有疑问请致电13644444444。
                    $templateParam = [
                        'hospitalname'  => str_replace('为您开放了云转诊平台的账号', '', $arr[1]),
                        'merchantno'    => str_replace('商户号为', '', $arr[2]),
                        'loginname'     => str_replace('登录账号为', '', $arr[3]),
                        'loginpass'     => str_replace('密码', '', $arr[4]),
                        'hospitalphone' => str_replace('。', '', str_replace('如有疑问请致电', '', $arr[7])),
                    ];
                    break;
                case 3:
                    //3 【云转诊】尊敬的用户， 华府医院为您开放了云转诊平台的账号，商户号为5110001，登录账号为18949151988，密码151988，为了您的账户安全，请您尽快登录并修改密码，如有疑问请致电028-85834591。
                    $templateParam = [
                        'hospitalname'  => str_replace('为您开放了云转诊平台的账号', '', $arr[1]),
                        'merchantno'    => str_replace('商户号为', '', $arr[2]),
                        'loginname'     => str_replace('登录账号为', '', $arr[3]),
                        'loginpass'     => str_replace('密码', '', $arr[4]),
                        'hospitalphone' => str_replace('。', '', str_replace('如有疑问请致电', '', $arr[7])),
                    ];
                    break;
            }

        }
        return $templateParam;
    }
}