<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/8/16
 * Time: 下午3:37
 */

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;

class MessageLog extends Model
{
    use ValidationTrait;

    //消息类型 1=>系统 2=>充值 3=>提现 4=>转诊 5=>分润  6=>转账支出 7=>转账收入 8=>挂号 9=>商城库存 10=>业务经理奖励
    const TYPE_SYSTEM = 1;
    const TYPE_CHARGE = 2;
    const TYPE_ENCASH = 3;
    const TYPE_TRANSFER = 4;
    const TYPE_SHARE = 5;
    const TYPE_ACCOUNT_OUT = 6;
    const TYPE_ACCOUNT_IN = 7;
    const TYPE_REGISTRATION = 8;
    const TYPE_PRODUCT_STOCK = 9;
    const TYPE_SALESMAN_BONUS = 10;

    //标记为 1=>未读 2=>已读
    const UNREAD_NOT = 1;
    const UNREAD_YES = 2;

    //消息发送方式 1=>推送消息 2=>短信通知
    const SENDWAY_PUSH = 1;
    const SENDWAY_SMS = 2;

    //0=>未被删除 1=>已被删除
    const IsDeleted_No = 0;
    const IsDeleted_Yes = 1;

    public $Id;

    public $Type;

    public $AcceptId;

    public $OrganizationId;

    public $SendWay;

    public $Content;

    public $ReleaseTime;

    public $Unread;

    public $IsDeleted;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('AcceptId', User::class, 'Id', ['alias' => 'User']);
    }

    public function getSource()
    {
        return 'MessageLog';
    }

    public static function add($type = 1, $acceptId = 0, $sendWay = 1, $content = '')
    {
        $messageLog = new MessageLog();
        $messageLog->Type = $type;
        $messageLog->AcceptId = $acceptId;
        $messageLog->SendWay = $sendWay;
        $messageLog->Content = $content;
        $messageLog->CreateTime = time();
        return $messageLog->save() ? true : $messageLog->getMessages();
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rule(['AcceptId'],
            new Digit([
                'message' => [
                    'AcceptId' => 'AcceptId必须为整形数字',
                ],
            ])
        );
        return $this->validate($validator);
    }

}