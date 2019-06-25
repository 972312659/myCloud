<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/11/26
 * Time: 上午11:01
 */

namespace App\Models;

use App\Validators\Mobile;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\StringLength;

class OfflinePay extends Model
{
    //状态 1=>待审核 2=>审核通过 3=>审核不通过 4=>充值成功 5=>关闭
    const STATUS_AUDIT = 1;
    const STATUS_PASS = 2;
    const STATUS_FAILED = 3;
    const STATUS_SUCCESS = 4;
    const STATUS_CLOSED = 5;
    const STATUS_NAME = [1 => '待审核', 2 => '审核通过', 3 => '审核未通过', 4 => '充值成功', 5 => '关闭'];

    public $Id;

    public $OrganizationId;

    public $Amount;

    public $AccountTitle;

    public $Status;

    public $Phone;

    public $Created;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'OfflinePay';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->add('Amount', new PresenceOf(['message' => '充值金额不能为空']));
        $validator->add('Amount', new Digit(['message' => '金额错误']));
        $validator->add('Status', new Digit(['message' => '状态错误']));
        $validator->rules('AccountTitle', [
            new PresenceOf(['message' => '打款户名不能为空']),
            new StringLength(["min" => 0, "max" => 30, "messageMaximum" => '打款户名最长不超过30个字符']),
        ]);
        // $validator->add('Phone', new Mobile(['message' => '手机号码错误']));
        return $this->validate($validator);
    }

    public function beforeCreate()
    {
        $this->Created = time();
    }

    public function afterCreate()
    {
        $this->log();
    }

    public function afterUpdate()
    {
        $this->log(true);
    }

    /**
     * @param bool $peach 是否是平台操作
     */
    public function log(bool $peach = false)
    {
        $id = $this->getDI()->getShared('session')->get('auth')['Id'];
        $offlinePayLog = new OfflinePayLog();
        $offlinePayLog->OfflinePayId = $this->Id;
        $offlinePayLog->Status = $this->Status;
        $offlinePayLog->UserId = $peach ? null : $id;
        $offlinePayLog->StaffId = $peach ? $id : null;
        $offlinePayLog->save();
    }
}