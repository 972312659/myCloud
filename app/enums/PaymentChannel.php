<?php

namespace App\Enums;


use App\Libs\PaymentChannel\IPaymentChannel;

class PaymentChannel
{
    private static $map = [];

    public function register(IPaymentChannel $channel)
    {
        self::$map[] = $channel;
    }

    public function options(): array
    {
        return array_map(function (IPaymentChannel $channel) {
            return $channel->getGateway();
        }, self::$map);
    }

    public function get($gateway): IPaymentChannel
    {
        foreach (self::$map as $channel) {
            if ($channel->getGateway() === $gateway) {
                return $channel;
            }
        }
        throw new \UnexpectedValueException('没有对应的支付渠道');
    }

    public function map(): array
    {
        return array_map(function (IPaymentChannel $channel) {
            return [
                'Name'    => $channel->getChannel(),
                'Gateway' => $channel->getGateway(),
                'Info'    => $channel->getRateInfo(),
                'Extra'   => $channel->getExtra(),
            ];
        }, self::$map);
    }
}