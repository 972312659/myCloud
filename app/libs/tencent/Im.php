<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/5/6
 * Time: 11:17 AM
 */

namespace App\Libs\tencent;

use App\Libs\Curl;
use Tencent\TLSSigAPI;

class Im
{
    //生成usersig需要先设置私钥
    private $privateKey = <<<'EOT'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgt/LPeZVlvD5c32rl
D3XAsoddKRIgGy2lSxD3cOTm9PuhRANCAAQeOz4+wiVwZdu0TqPil74b+M+OBZo5
pWrHjpZPpTotFJF2lNQRCdex0qxpg3QEIpCtXUXz+Tg5Tc1DTlKxfmlr
-----END PRIVATE KEY-----
EOT;
    /**
     * 设置在腾讯云申请的appid
     * @var int
     */
    private $sdkappid = 1400200900;
    /**
     * 管理员账号
     * @var string
     */
    private $admin = "administrator";
    private $api;

    private $url = "https://console.tim.qq.com";


    public function __construct()
    {
        $this->api = new TLSSigAPI();
        $this->api->SetAppid($this->sdkappid);
        $this->api->SetPrivateKey($this->privateKey);
    }

    public function getAppId()
    {
        return $this->sdkappid;
    }

    public function createUsersig(string $identifier): string
    {
        return $this->api->genSig($identifier);
    }

    public function createAdminUsersig(): string
    {
        return $this->adminUsersig = $this->api->genSig($this->admin);
    }

    public function getOnlineStatusForOne(\Redis $redis, $identifier)
    {
        $rand = mt_rand(10000000, 99999999);
        $urlEnd = "/v4/openim/querystate?sdkappid=%s&identifier=%s&usersig=%s&random=%s&apn=1&contenttype=json";
        $url = sprintf($this->url . $urlEnd, $this->sdkappid, $this->admin, $this->createAdminUsersig(), $rand);
        $data = [
            'To_Account' => [$identifier],
        ];
        $curl = new Curl();
        $result = $curl->request('POST', $url, json_encode($data));
        $result = json_decode($result, true);
        if ($result['ActionStatus'] == 'OK') {
            $status = reset($result['QueryResult']);
            $key = $status['To_Account'];
            $value = $status['State'];
            switch ($value) {
                case "Online":
                    $this->setOnlineStatus($redis, $key);
                    break;
                case "Offline":
                    $redis->del($key);
                    break;

            }
        }
    }

    public function setOnlineStatus(\Redis $redis, string $key)
    {
        // $redisValue = $redis->get($key);
        $keyValue = "free";
        // if ($redisValue) {
        //     if (in_array($redisValue, ["free", "busy"])) {
        //         $keyValue = $redisValue;
        //     }
        // }
        $redis->getSet($key, $keyValue);
    }
}