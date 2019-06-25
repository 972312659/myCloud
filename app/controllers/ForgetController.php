<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/11/28
 * Time: 下午3:58
 */

namespace App\Controllers;

use App\Enums\MessageTemplate;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Confirmation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Regex;
use Phalcon\Validation\Validator\StringLength;

class ForgetController extends Controller
{
    /**
     * 验证手机号码是否存在
     * @Anonymous
     */
    public function auditPhoneAction()
    {
        /** @var User $user */
        $user = User::findFirst([
            'conditions' => 'Phone=?0',
            'bind'       => [$this->request->get('Phone')],
        ]);
        if (!$user) {
            throw new LogicException('手机号码错误', Status::BadRequest);
        }
        /** @var OrganizationUser $organizationUser */
        $organizationUser = OrganizationUser::findFirst([
            'conditions' => 'UserId=?0',
            'bind'       => [$user->Id],
        ]);
        if (!$organizationUser) {
            throw new LogicException('用户未绑定任何机构', Status::BadRequest);
        }
        $result['Token'] = $this->session->getId();
        $result['Id'] = $user->Id;
        $result['Phone'] = $user->Phone;
        $this->session->set('user', $result);
        $this->response->setJsonContent($result);
    }

    /**
     * 获取机构列表
     * @Anonymous
     */
    public function getOptionsAction()
    {
        $session = $this->session->get('user');
        if (!$session) {
            throw new LogicException('请重新验证手机号码', Status::Unauthorized);
        }
        /** @var OrganizationUser $organizationUser */
        $organizationUser = OrganizationUser::findFirst([
            'conditions' => 'UserId=?0',
            'bind'       => [$this->session->get('user')['Id']],
        ]);
        /** @var Organization $organization */
        $organization = Organization::findFirst([
            'columns'    => 'Id,Name',
            'conditions' => 'Id=?0',
            'bind'       => [$organizationUser->OrganizationId],
        ]);
        //随机三个
        $rand = 3;
        $phql = "SELECT Id,Name FROM `Organization` 
WHERE Id >= (SELECT floor(RAND() * (SELECT MAX(Id) FROM `Organization`)))  and Id!={$organization->Id} and Id!=0 and Fake=0
ORDER BY Id LIMIT {$rand};";
        $result = $this->db->query($phql)->fetchAll();
        $result[] = $organization->toArray();
        shuffle($result);
        $this->response->setJsonContent($result);
    }

    /**
     * 验证选项
     * @Anonymous
     */
    public function auditOptionAction()
    {
        $session = $this->session->get('user');
        if (!$session) {
            throw new LogicException('请重新验证手机号码', Status::Unauthorized);
        }
        /** @var OrganizationUser $organizationUser */
        $organizationUser = OrganizationUser::findFirst([
            'conditions' => 'UserId=?0 and OrganizationId=?1',
            'bind'       => [$this->session->get('user')['Id'], $this->request->get('Id')],
        ]);
        if (!$organizationUser) {
            throw new LogicException('机构错误', Status::BadRequest);
        }
        $session['OrganizationId'] = $organizationUser->OrganizationId;
        $this->session->set('user', $session);
    }

    /**
     * 获取验证码
     * @Anonymous
     */
    public function sendCodeAction()
    {
        $session = $this->session->get('user');
        if (!$session) {
            throw new LogicException('请重新验证手机号码', Status::Unauthorized);
        }
        /** @var OrganizationUser $organizationUser */
        $organizationUser = OrganizationUser::findFirst([
            'conditions' => 'UserId=?0 and OrganizationId=?1',
            'bind'       => [$this->session->get('user')['Id'], $this->session->get('user')['OrganizationId']],
        ]);
        if (!$organizationUser) {
            throw new LogicException('验证未通过', Status::BadRequest);
        }

        $captcha = (string)random_int(100000, 999999);
        $content = MessageTemplate::load('captcha', MessageTemplate::METHOD_SMS, $captcha);
        $this->sms->send($session['Phone'], $content, 'forget', $captcha);

        $session['Captcha'] = $captcha;
        $this->session->set('user', $session);
    }

    /**
     * 修改密码
     * @Anonymous
     */
    public function changePasswordAction()
    {
        $session = $this->session->get('user');
        if (!$session) {
            throw new LogicException('请重新验证手机号码', Status::Unauthorized);
        }
        /** @var OrganizationUser $organizationUser */
        $organizationUser = OrganizationUser::findFirst([
            'conditions' => 'UserId=?0 and OrganizationId=?1',
            'bind'       => [$this->session->get('user')['Id'], $this->session->get('user')['OrganizationId']],
        ]);
        if (!$organizationUser) {
            throw new LogicException('验证未通过', Status::BadRequest);
        }
        $validator = new Validation();
        $validator->rules('Password', [
            new PresenceOf(['message' => '请输入密码']),
            new StringLength(["min" => 6, "max" => 20, "messageMaximum" => '密码长度6-20位', "messageMinimum" => "密码长度6-20位"]),
            new Confirmation(['message' => '两次密码不一致', 'with' => 'RePassword']),
        ]);
        $validator->rules('RePassword', [
            new PresenceOf(['message' => '确认密码不能为空']),

        ]);
        $validator->rules('Captcha', [
            new PresenceOf(['message' => '验证码不能为空']),
            new Regex([
                "pattern" => "/^{$session['Captcha']}$/",
                "message" => "验证码错误",
            ]),
        ]);
        $ex = new ParamException(Status::BadRequest);
        $ret = $validator->validate($this->request->getPost());
        if (count($ret) > 0) {
            $ex->loadFromMessage($ret);
            throw $ex;
        }
        $user = User::findFirst($session['Id']);
        if (!$user) {
            throw new LogicException('用户不存在', Status::BadRequest);
        }
        $user->Password = $this->security->hash($this->request->get('Password'));
        //设置验证场景
        $user->setScene(User::SCENE_AUTH_EDIT);
        if ($user->save() === false) {
            $ex->loadFromModel($user);
            throw $ex;
        }
        $this->session->destroy('user');
        $this->response->setJsonContent(['message' => '修改成功']);
    }
}