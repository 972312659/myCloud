<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/8/7
 * Time: 15:11
 */

namespace App\Libs;

class Yimei
{
    const API = 'http://shmtn.b2m.cn/inter/sendSingleSMS';
    const APP_ID = 'EUCP-EMY-SMS1-16LSO';
    const SECRET_KEY = '2C6BA5DEB6A857AB';


    public function send($mobile, $content)
    {
        $header = [
            sprintf('appId: %s', self::APP_ID),
        ];
        $data = [
            'mobile'             => $mobile,
            'content'            => '【云转诊平台】' . $content,
            'requestTime'        => (int)(microtime(true) * 1000),
            'requestValidPeriod' => 600,
        ];
        $ret = json_encode($data, JSON_UNESCAPED_UNICODE);
        $ret = openssl_encrypt($ret, 'AES-128-ECB', self::SECRET_KEY, OPENSSL_RAW_DATA);
        $ch = curl_init(self::API);
        //curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $ret);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
}