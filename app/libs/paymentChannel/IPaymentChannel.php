<?php

namespace App\Libs\PaymentChannel;

use Phalcon\Validation;

/**
 * Interface IPaymentChannel
 * 所有金额单位在没有特殊说明的情况下都为"分"
 * @package App\Libs\PaymentChannel
 */
interface IPaymentChannel
{
    /**
     * 获取支付网关编号 每个渠道的网关必须唯一
     * @return int
     */
    public function getGateway(): int;

    /**
     * 获取渠道名称
     * @return string
     */
    public function getChannel(): string;

    /**
     * 获取费率说明
     * @return string
     */
    public function getRateInfo(): string;

    /**
     * 获取渠道附加数据
     * @return mixed
     */
    public function getExtra();

    /**
     * 获取手续费
     * @param $amount int 参与计算手续费的金额
     * @return int
     */
    public function getFee($amount): int;

    /**
     * 总金额
     * @param $amount int 参与计算手续费的金额
     * @return int
     */
    public function getTotal($amount): int;

    /**
     * 获取最大可提现额度
     * @param $amount int 可用余额
     * @return int
     */
    public function getAvailable($amount): int;

    /**
     * 提现验证器
     * @return Validation
     */
    public function encashValidation(): Validation;

    /**
     * @param $result array 向三方支付平台请求转账后返回的body数据
     * @return PaymentChannelResult
     */
    public function handleEncashResult($result): PaymentChannelResult;
}