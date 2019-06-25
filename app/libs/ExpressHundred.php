<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/10/15
 * Time: 上午10:50
 * For:快递100
 */

namespace App\Libs;

use GuzzleHttp\Client;

class ExpressHundred
{
    private $url = 'https://poll.kuaidi100.com/poll/query.do';
    private $select_key = 'tEjrCnhL2258';
    private $select_customer = 'FA56F7B0AE781DD4D1862F2DBB83FEF1';
    private $client;

    public function __construct()
    {
        $this->client = new Client();
    }

    public function get(string $com, string $num)
    {
        $post_data = [
            'customer' => $this->select_customer,
            'param'    => json_encode(['com' => $com, 'num' => $num]),
        ];
        $post_data['sign'] = strtoupper(md5($post_data['param'] . $this->select_key . $post_data['customer']));
        $o = "";
        foreach ($post_data as $k => $v) {
            $o .= "$k=" . urlencode($v) . "&";        //默认UTF-8编码格式
        }
        $post_data = substr($o, 0, -1);
        $client = new Client();
        $response = $client->post($this->url . '?' . $post_data, []);
        $result = json_decode($response->getBody()->getContents(), true);
        if ($result['message'] == 'ok') {
            $data = ['status' => true, 'message' => $result['data']];
        } else {
            $data = ['status' => false, 'message' => $result['message']];
        }
        return $data;
    }
}
