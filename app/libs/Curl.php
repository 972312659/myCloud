<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/11/22
 * Time: 下午3:06
 */

namespace App\Libs;


class Curl
{
    //cookie临时文件
    private $tempCookie;

    /**
     * @param string $http (Http method)
     * @param $url
     * @param array $data
     * @param string $way
     * @return mixed
     */
    public function request($http = 'GET', $url, $data = [], $way = 'login')
    {
        //初始化
        $ch = curl_init();
        //设置选项，包括URL

        $file = tempnam(sys_get_temp_dir(), time());

        curl_setopt($ch, CURLOPT_URL, $url);
        //执行并获取HTML文档内容
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $http);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        switch ($way) {
            case 'login':
                curl_setopt($ch, CURLOPT_COOKIEJAR, $file); //存储cookies
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                break;
            default ://check
        }
        // curl_setopt($ch, CURLOPT_HTTPHEADER, 0);//head
        $output = curl_exec($ch);
        //释放curl句柄
        curl_close($ch);
        //获得cookie
        $cookies = file_get_contents($file);
        //删除临时文件
        unlink($file);
        $array = array_filter(explode(',', preg_replace('/\s+/', ',', $cookies)));
        $this->tempCookie = array_pop($array);
        // file_put_contents('./captcha.jpg', $output);
        //打印获得的数据
        return $output;
    }

    /**
     * 获取cookie
     */
    public function getCookie($http, $url, $data = [], $way)
    {
        $output = $this->request($http, $url, $data, $way);
        $out = json_decode($output);
        if ($out->state) {
            $this->request($http, $url, $data, 'login');
        }
        return $this->tempCookie;
    }

    public function captcha($http, $url, $way, $data = [])
    {
        $output = $this->request($http, $url, $data, $way);
        //保存图形验证码临时文件
        @file_put_contents("114captcha.jpg", $output);
    }

    /**
     * simple request
     */
    public static function gain($http = 'GET', $url, $headerType = null, $data = null)
    {
        //初始化
        $ch = curl_init();
        //设置选项，包括URL
        curl_setopt($ch, CURLOPT_URL, $url);
        //执行并获取HTML文档内容
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $http);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        if ($headerType) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:' . $headerType]);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $output = curl_exec($ch);
        //释放curl句柄
        curl_close($ch);
        //打印获得的数据
        return $output;
    }
}