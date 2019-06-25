<?php

namespace App\Libs\PaymentChannel;

use Phalcon\Validation;
use Phalcon\Validation\Validator\Between;
use Phalcon\Validation\Validator\InclusionIn;
use Phalcon\Validation\Validator\PresenceOf;

class Wxpay implements IPaymentChannel
{
    const Gateway = 2;

    const Name = '银行卡';

    const Info = '提现时平台须收取0.1%的手续费，最低单笔收取1元，手续费将会从余额中扣除，提现金额不可超过最大可提现额。';

    const Rate = 1 / 1000;

    const Banks = [
        ['Name' => '中国工商银行', 'Value' => 1002, 'Code' => 'ICBC', 'Url' => 'https://referral-store.100cbc.com/banks/1002.png'],
        ['Name' => '中国农业银行', 'Value' => 1005, 'Code' => 'ABC', 'Url' => 'https://referral-store.100cbc.com/banks/1005.png'],
        ['Name' => '中国银行', 'Value' => 1026, 'Code' => 'BOC', 'Url' => 'https://referral-store.100cbc.com/banks/1026.png'],
        ['Name' => '中国建设银行', 'Value' => 1003, 'Code' => 'CCB', 'Url' => 'https://referral-store.100cbc.com/banks/1003.png'],
        ['Name' => '招商银行', 'Value' => 1001, 'Code' => 'CMB', 'Url' => 'https://referral-store.100cbc.com/banks/1001.png'],
        ['Name' => '中国邮政储蓄银行', 'Value' => 1066, 'Code' => 'PSBC', 'Url' => 'https://referral-store.100cbc.com/banks/1066.png'],
        ['Name' => '交通银行', 'Value' => 1020, 'Code' => 'COMM', 'Url' => 'https://referral-store.100cbc.com/banks/1020.png'],
        ['Name' => '上海浦东发展银行', 'Value' => 1004, 'Code' => 'SPDB', 'Url' => 'https://referral-store.100cbc.com/banks/1004.png'],
        ['Name' => '中国民生银行', 'Value' => 1006, 'Code' => 'CMBC', 'Url' => 'https://referral-store.100cbc.com/banks/1006.png'],
        ['Name' => '兴业银行', 'Value' => 1009, 'Code' => 'CIB', 'Url' => 'https://referral-store.100cbc.com/banks/1009.png'],
        ['Name' => '平安银行', 'Value' => 1010, 'Code' => 'SPABANK', 'Url' => 'https://referral-store.100cbc.com/banks/1010.png'],
        ['Name' => '中信银行', 'Value' => 1021, 'Code' => 'CITIC', 'Url' => 'https://referral-store.100cbc.com/banks/1021.png'],
        ['Name' => '华夏银行', 'Value' => 1025, 'Code' => 'HXBANK', 'Url' => 'https://referral-store.100cbc.com/banks/1025.png'],
        ['Name' => '广东发展银行', 'Value' => 1027, 'Code' => 'GDB', 'Url' => 'https://referral-store.100cbc.com/banks/1027.png'],
        ['Name' => '中国光大银行', 'Value' => 1022, 'Code' => 'CEB', 'Url' => 'https://referral-store.100cbc.com/banks/1022.png'],
        ['Name' => '北京银行', 'Value' => 1032, 'Code' => 'BJBANK', 'Url' => 'https://referral-store.100cbc.com/banks/1032.png'],
        ['Name' => '宁波银行', 'Value' => 1056, 'Code' => 'NBBANK', 'Url' => 'https://referral-store.100cbc.com/banks/1056.png'],
    ];

    /**
     * 单笔提现最小手续费 1 元
     */
    const Min = 100;

    public function getGateway(): int
    {
        return self::Gateway;
    }

    public function getChannel(): string
    {
        return self::Name;
    }

    public function getRateInfo(): string
    {
        return self::Info;
    }

    public function getExtra()
    {
        return self::Banks;
    }

    public function getTotal($amount): int
    {
        return $amount + $this->getFee($amount);
    }

    public function getFee($amount): int
    {
        $fee = ceil($amount * self::Rate);
        return $fee > self::Min ? $fee : self::Min;
    }

    public function encashValidation(): Validation
    {
        $validation = new Validation();
        $validation->rules('Name', [
            new PresenceOf(['message' => '姓名不能为空']),
        ]);
        $validation->rules('Bank', [
            new PresenceOf(['message' => '银行不能为空']),
            new InclusionIn(['message' => '不支持的银行', 'domain' => array_column(self::Banks, 'Value')]),
        ]);
        $validation->rules('Account', [
            new PresenceOf(['message' => '银行卡号不能为空']),
        ]);
        $validation->rules('Amount', [
            new PresenceOf(['message' => '提现金额不能为空']),
            new Between(['message' => '提现金额必须在1元到50000元范围内', 'minimum' => 100, 'maximum' => 5000000]),
        ]);
        $validation->rules('Password', [
            new PresenceOf(['message' => '登录密码不能为空']),
        ]);
        return $validation;
    }

    /**
     * @param $result string 向三方支付平台请求转账后返回的body数据
     * @return PaymentChannelResult
     */
    public function handleEncashResult($result): PaymentChannelResult
    {
        $paymentResult = new PaymentChannelResult();
        $paymentResult->Gateway = self::Gateway;
        if ($result['return_code'] === 'SUCCESS' && $result['result_code'] === 'SUCCESS') {
            $paymentResult->Success = true;
        } else {
            $paymentResult->Success = false;
        }
        $paymentResult->Code = $result['err_code'];
        $paymentResult->Message = $result['err_code_des'] ?: $result['return_msg'];
        return $paymentResult;
    }

    public function getAvailable($amount): int
    {
        if ($amount <= 0) {
            return 0;
        }
        return $amount > self::Min / self::Rate ? floor($amount / (1 + self::Rate)) : $amount - self::Min;
    }
}
