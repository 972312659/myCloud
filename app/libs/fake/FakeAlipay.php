<?php

namespace App\Libs\fake;

use App\Libs\Alipay;

class FakeAlipay
{
    public $return_url;

    public $notify_url;

    public $trade_no;

    public $subject;

    public $fee;

    public function receipt()
    {
        $data = [
            'partner'           => Alipay::PARTNER_ID,
            '_input_charset'    => Alipay::CHARSET,
            'notify_url'        => empty($this->notify_url) ? Alipay::NOTIFY_URL : $this->notify_url,
//            'return_url'        => $this->return_url,
            'out_trade_no'      => $this->trade_no,
            'subject'           => $this->subject,
            'payment_type'      => '1',
            'total_fee'         => Alipay::fen2yuan($this->fee),
            'seller_id'         => Alipay::PARTNER_ID,
            'qr_pay_mode'       => '2', // 票宝为航旅收银台无法使用二维码
            'service'           => Alipay::RECEIPT_SERVICE,
            'disable_paymethod' => 'creditCardExpress^point^voucher',
            'extra_common_param'      => 'fake'
        ];

        return Alipay::GATEWAY . '?' . Alipay::sign($data);
    }
}
