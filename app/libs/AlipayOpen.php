<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/12/4
 * Time: 14:04
 */

namespace App\Libs;


class AlipayOpen
{
    const KEY_PATH = '/usr/local/src/pem';

    const OPENAPI_PRIV_KEY_PATH = self::KEY_PATH . '/openapi/rsa_private_key.pem';

    const OPENAPI_ALIPAY_PUB_KEY_PATH = self::KEY_PATH . '/openapi/rsa_public_key.pem';

    const GATEWAY = APP_DEBUG ? 'https://openapi.alipaydev.com/gateway.do' : 'https://openapi.alipay.com/gateway.do';

    const APP_ID = APP_DEBUG ? '2016082100308247' : '2017120400364205';

    const PARTNER_EMAIL = APP_DEBUG ? 'itjjny8710@sandbox.com' : 'tzpay@100cbc.com';

    const CHARSET = 'UTF-8';

    const NOTIFY_URL = APP_DEBUG ? 'https://cloud.dev.100cbc.com/payment/alipay' : 'https://cloud.100cbc.com/payment/alipay';

    const RECEIPT_MOBILE_METHOD = 'alipay.trade.app.pay';

    const PAYMENT_METHOD = 'alipay.fund.trans.toaccount.transfer';

    const QUERY_METHOD = 'alipay.fund.trans.order.query';

    /**
     * APP支付(云转诊商户充值)
     * @see https://docs.open.alipay.com/204/105465
     */
    public static function receipt(string $tradeNo, string $subject, int $fee): string
    {
        $biz = [
            'subject'              => $subject,
            'out_trade_no'         => $tradeNo,
            'total_amount'         => Alipay::fen2yuan($fee),
            'product_code'         => 'QUICK_MSECURITY_PAY',
            'disable_pay_channels' => 'credit_group',
        ];
        $data = [
            'app_id'      => self::APP_ID,
            'method'      => self::RECEIPT_MOBILE_METHOD,
            'charset'     => self::CHARSET,
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'notify_url'  => self::NOTIFY_URL,
            'biz_content' => json_encode($biz),
            'sign_type'   => 'RSA2',
        ];
        return self::sign($data);
    }

    /**
     * 数据签名
     * @param array $data
     * @return string
     * @throws \Exception
     */
    private static function sign(array $data): string
    {
        ksort($data, SORT_STRING);
        $rsa = file_get_contents(self::OPENAPI_PRIV_KEY_PATH);
        $res = openssl_get_privatekey($rsa);
        if ($res) {
            $ret = [];
            array_walk($data, function ($value, $key) use (&$ret) {
                $ret[] = sprintf('%s=%s', $key, $value);
            });
            openssl_sign(implode('&', $ret), $sign, $res, OPENSSL_ALGO_SHA256);
            openssl_free_key($res);
            $data['sign'] = base64_encode($sign);
            return http_build_query($data);
        }
        throw new \Exception('wrong key');
    }

    /**
     * 单笔付款到支付宝账户(云转诊商户提现)
     * @see https://docs.open.alipay.com/api_28/alipay.fund.trans.toaccount.transfer
     */
    public static function payment(AlipayTarget $target): string
    {
        $biz = [
            'payee_type'    => 'ALIPAY_LOGONID',
            'payee_account' => $target->account,
            'out_biz_no'    => $target->serialNo,
            'amount'        => Alipay::fen2yuan($target->fee),
            'remark'        => $target->remarks,
        ];
        $data = [
            'app_id'      => self::APP_ID,
            'method'      => self::PAYMENT_METHOD,
            'charset'     => self::CHARSET,
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'biz_content' => json_encode($biz),
            'sign_type'   => 'RSA2',
        ];
        return self::sign($data);
    }

    /**
     * 查询转账订单接口
     * @param $serialNo
     * @return string
     */
    public static function query($serialNo)
    {
        $biz = [
            'out_biz_no' => $serialNo,
        ];
        $data = [
            'app_id'      => self::APP_ID,
            'method'      => self::QUERY_METHOD,
            'charset'     => self::CHARSET,
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'biz_content' => json_encode($biz),
            'sign_type'   => 'RSA2',
        ];
        return self::sign($data);
    }

    public static function verify($data): bool
    {
        $type = $data['sign_type'];
        $sign = $data['sign'];
        unset($data['sign_type'], $data['sign']);
        ksort($data, SORT_STRING);
        $ret = [];
        if (strtolower($type) === 'rsa2') {
            array_walk($data, function ($value, $key) use (&$ret) {
                $ret[] = sprintf('%s=%s', $key, $value);
            });
            $rsa = file_get_contents(self::OPENAPI_ALIPAY_PUB_KEY_PATH);
            $res = openssl_get_publickey($rsa);
            $result = openssl_verify(implode('&', $ret), base64_decode($sign), $res, OPENSSL_ALGO_SHA256) === 1;
            if ($res) {
                openssl_free_key($res);
            }
            return $result;
        }
        return false;
    }
}