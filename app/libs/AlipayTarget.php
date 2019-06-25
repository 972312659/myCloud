<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/8/10
 * Time: 12:43
 */

namespace App\Libs;


class AlipayTarget
{
    /**
     * @var string 流水号 不超过64字节
     */
    public $serialNo;

    /**
     * @var string 收款方账号 小于100字节
     */
    public $account;

    /**
     * @var string 收款方姓名
     */
    public $name;

    /**
     * @var int 金额(分)
     */
    public $fee;

    /**
     * @var string 备注 不超过200字节
     */
    public $remarks;

    public static function load(string $detail): self
    {
        $ret = explode('^', $detail);
        $instance = new self();
        $instance->serialNo = $ret[0];
        $instance->account = $ret[1];
        $instance->name = $ret[2];
        $instance->fee = Alipay::yuan2fen($ret[3]);
        $instance->remarks = $ret[5] === 'null' ? '操作成功': $ret[5];
        return $instance;
    }

    /**
     * 组装付款详细数据
     * @return string
     */
    public function compose(): string
    {
        return implode('^', [
            $this->serialNo,
            $this->account,
            $this->name,
            Alipay::fen2yuan($this->fee),
            $this->remarks,
        ]);
    }
}