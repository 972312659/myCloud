<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/8/10
 * Time: 10:53
 */

namespace App\Libs;


class Alipay
{
    const MAPI_PRIV_KEY_PATH = '/usr/local/src/pem/mapi/rsa_private_key.pem';

    const MAPI_ALIPAY_PUB_KEY_PATH = '/usr/local/src/pem/mapi/rsa_public_key.pem';

    const GATEWAY = 'https://mapi.alipay.com/gateway.do';

    const PARTNER_ID = '2088801432717360';

    const PARTNER_NAME = '成都票宝网络有限责任公司';

    const PARTNER_EMAIL = 'pay@mypb.cn';

    const CODE = 'cbbw2k1l2zt551nhrgixp42meah5dt66';

    const CHARSET = 'UTF-8';

    const NOTIFY_URL = 'https://cloud.100cbc.com/payment/alipay';

    const RECEIPT_SERVICE = 'create_direct_pay_by_user';

    const PAYMENT_SERVICE = 'batch_trans_notify';

    /**
     * 即时到账(云转诊商户充值)
     * @see https://doc.open.alipay.com/doc2/detail?treeId=62&articleId=103566&docType=1
     * @param string $tradeNo 订单号
     * @param string $subject 商品名称
     * @param int $fee        金额(分)
     * @return string
     * @throws \Exception
     */
    public static function receipt(string $tradeNo, string $subject, int $fee): string
    {
        $data = [
            'partner'           => self::PARTNER_ID,
            '_input_charset'    => self::CHARSET,
            'notify_url'        => self::NOTIFY_URL,
            'return_url'        => 'https://yun.100cbc.com/master/account/my.html',
            'out_trade_no'      => $tradeNo,
            'subject'           => $subject,
            'payment_type'      => '1',
            'total_fee'         => self::fen2yuan($fee),
            'seller_id'         => self::PARTNER_ID,
            'qr_pay_mode'       => '2', // 票宝为航旅收银台无法使用二维码
            'service'           => self::RECEIPT_SERVICE,
            'disable_paymethod' => 'creditCardExpress^point^voucher',
        ];
        return self::GATEWAY . '?' . self::sign($data);
    }

    public static function fen2yuan(int $fen): string
    {
        $yuan = $fen / 100;
        return number_format($yuan, 2, '.', '');
    }

    /**
     * 数据签名
     * @param array $data
     * @return string
     * @throws \Exception
     */
    public static function sign(array $data): string
    {
        ksort($data, SORT_STRING);
        $rsa = file_get_contents(self::MAPI_PRIV_KEY_PATH);
        $res = openssl_get_privatekey($rsa);
        if ($res) {
            $ret = [];
            array_walk($data, function ($value, $key) use (&$ret) {
                $ret[] = sprintf('%s=%s', $key, $value);
            });
            openssl_sign(implode('&', $ret), $sign, $res);
            openssl_free_key($res);
            $data['sign_type'] = 'RSA';
            $data['sign'] = base64_encode($sign);
            return http_build_query($data);
        }
        throw new \Exception('wrong key');
    }

    public static function yuan2fen(string $yuan): int
    {
        $yuan = preg_replace('/\s|,/', '', $yuan);
        if (is_numeric($yuan)) {
            return $yuan * 100;
        }
        throw new \LogicException('错误的金额');
    }

    /**
     * 批量付款到支付宝账户(云转诊商户提现)
     * @see https://doc.open.alipay.com/doc2/detail?treeId=64&articleId=103569&docType=1
     * @param string $batchNo            批量付款批次号用作业务幂等性控制的依据
     * @param AlipayTarget[] ...$targets 收款方信息
     * @return string 返回url
     * @throws \Exception
     */
    public static function payment($batchNo, AlipayTarget ...$targets): string
    {
        $details = array_map(function (AlipayTarget $item) {
            return $item->compose();
        }, $targets);
        $batchFee = array_reduce($targets, function (int $carry, AlipayTarget $item) {
            return $carry + $item->fee;
        }, 0);
        return self::sign([
            'service'        => self::PAYMENT_SERVICE,
            'partner'        => self::PARTNER_ID,
            '_input_charset' => self::CHARSET,
            'notify_url'     => self::NOTIFY_URL,
            'account_name'   => self::PARTNER_NAME,
            'detail_data'    => implode('|', $details),
            'batch_no'       => $batchNo,
            'batch_num'      => count($targets),
            'batch_fee'      => self::fen2yuan($batchFee),
            'email'          => self::PARTNER_EMAIL,
            'pay_date'       => date('Ymd'),
        ]);
    }

    public static function verify($data): bool
    {
        $type = $data['sign_type'];
        $sign = $data['sign'];
        unset($data['sign_type'], $data['sign']);
        ksort($data, SORT_STRING);
        $ret = [];
        if (strtolower($type) === 'rsa') {
            array_walk($data, function ($value, $key) use (&$ret) {
                $ret[] = sprintf('%s=%s', $key, $value);
            });
            $rsa = file_get_contents(self::MAPI_ALIPAY_PUB_KEY_PATH);
            $res = openssl_get_publickey($rsa);
            $result = openssl_verify(implode('&', $ret), base64_decode($sign), $res) === 1;
            if ($res) {
                openssl_free_key($res);
            }
            return $result;
        }
        if (strtolower($type) === 'md5') {
            array_walk($data, function ($value, $key) use (&$ret) {
                $ret[] = sprintf('%s=%s', $key, urldecode($value));
            });
            return md5(implode('&', $ret) . self::CODE) === $sign;
        }
        return false;
    }
}