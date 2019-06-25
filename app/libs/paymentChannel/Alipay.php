<?php

namespace App\Libs\PaymentChannel;

use Phalcon\Validation;
use Phalcon\Validation\Validator\Between;
use Phalcon\Validation\Validator\PresenceOf;

class Alipay implements IPaymentChannel
{
    const Gateway = 1;

    const Name = '支付宝';

    const Info = '提现时平台须收取0.1%的手续费，手续费将会从余额中扣除，提现金额不可超过最大可提现额。';

    const Rate = 1 / 1000;

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
        return null;
    }

    public function getTotal($amount): int
    {
        return $amount + $this->getFee($amount);
    }

    public function getFee($amount): int
    {
        return ceil($amount * self::Rate);
    }

    public function encashValidation(): Validation
    {
        $validation = new Validation();
        $validation->rules('Account', [
            new PresenceOf(['message' => '账号不能为空']),
        ]);
        $validation->rules('Name', [
            new PresenceOf(['message' => '姓名不能为空']),
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

    public function handleEncashResult($result): PaymentChannelResult
    {
        $paymentResult = new PaymentChannelResult();
        $data = $result['alipay_fund_trans_toaccount_transfer_response'];
        $paymentResult->Success = $data['code'] === '10000';
        $paymentResult->Gateway = self::Gateway;
        $paymentResult->Code = $data['sub_code'];
        $paymentResult->Message = $data['sub_msg'];
        return $paymentResult;
    }

    public function getAvailable($amount): int
    {
        if ($amount <= 0) {
            return 0;
        }
        return floor($amount / (1 + self::Rate));
    }
}
