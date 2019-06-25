<?php

namespace App\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use GuzzleHttp\Client;

class UnionpayController extends Controller
{
    const Signature = 'Fox9527';

    const TokenKey = 'unionpay:token';

    const AppId = 'up_1j74h0djf3uh_sqvs';

    const Gateway = 'https://openapi.unionpay.com/upapi/';

    const AppSecret = '439178f0787d9d679eb7b98f8a0c6dfd';

    const ApiToken = 'cardbin/token';

    const ApiCardBin = 'cardbin/cardinfo';

    /**
     * 查询银行卡信息
     * @Anonymous
     */
    public function cardCheckAction()
    {
        $token = $this->redis->get(self::TokenKey);
        if (!$token) {
            $token = $this->getToken();
        }
        $client = new Client(['base_uri' => self::Gateway]);
        $body = \GuzzleHttp\json_encode(['cardNo' => $this->request->get('CardNumber')]);
        $timestamp = (int)(microtime(true) * 1000);
        $response = $client->post(self::ApiCardBin, [
            'query'   => [
                'token' => $token,
                'sign'  => hash('sha256', self::Signature . $body . $timestamp),
                'ts'    => $timestamp,
            ],
            'headers' => ['Content-Type' => 'application/json;utf-8'],
            'body'    => $body,
        ]);
        if ($response->getStatusCode() !== Status::OK) {
            throw new LogicException('服务不可用', Status::InternalServerError);
        }
        $result = \GuzzleHttp\json_decode($response->getBody()->getContents(), true);
        $this->response->setJsonContent($result);
    }

    private function getToken()
    {
        $client = new Client(['base_uri' => self::Gateway]);
        $response = $client->get(self::ApiToken, ['query' => [
            'app_id'     => self::AppId,
            'app_secret' => self::AppSecret,
        ]]);
        if ($response->getStatusCode() !== Status::OK) {
            throw new LogicException('服务不可用', Status::InternalServerError);
        }
        $result = \GuzzleHttp\json_decode($response->getBody()->getContents(), true);
        $this->redis->setex(self::TokenKey, $result['expire_in'] - 120, $result['token']);
        return $result['token'];
    }
}
