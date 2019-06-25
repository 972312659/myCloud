<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/9/28
 * Time: 下午4:16
 */

namespace App\Admin\Controllers;


use App\Enums\MessageTemplate;
use App\Enums\Status;
use App\Exceptions\ParamException;
use App\Libs\Sms;
use App\Validators\Mobile;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;

class MessageController extends Controller
{
    public function sendSomeoneAction()
    {
        if ($this->session->get('auth')) {
            if ($this->request->isPost()) {
                $exp = new ParamException(Status::BadRequest);
                $validator = new Validation();
                $validator->rules('Phone', [
                    new PresenceOf(['message' => '手机号不能为空']),
                    new Mobile(['message' => '请输入正确的手机号']),
                ]);
                $validator->rules('Content', [
                    new PresenceOf(['message' => '内容不能为空']),
                ]);
                $ret = $validator->validate($this->request->getPost());
                if ($ret->count() > 0) {
                    $exp->loadFromMessage($ret);
                    throw $exp;
                }
                $content = MessageTemplate::SMS_PREFIX . $this->request->getPost('Content');
                $phone = (string)trim($this->request->getPost('Phone'));
                $sms = new Sms($this->queue);
                $sms->sendMessage($phone, $content, $this->request->get('Extend') ?: null);
            }
        }
    }
}