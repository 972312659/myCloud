<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/31
 * Time: 下午7:32
 */

namespace App\Controllers;

use App\Enums\MessageTemplate;
use App\Enums\RedisName;
use App\Enums\Status;
use App\Enums\WebrtcName;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\DiffBetweenTwoDays;
use App\Libs\tencent\Im;
use App\Models\IllnessForDoctorIdentification;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationRelationship;
use App\Models\OrganizationSendMessageConfig;
use App\Models\OrganizationUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserSignature;
use Phalcon\Http\Response;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Confirmation;
use App\Validators\Mobile;
use Phalcon\Validation\Validator\PresenceOf;
use Tencent\TLSSigAPI;

class AuthController extends Controller
{
    /**
     * 网点app端登录
     * @Anonymous
     */
    public function loginAction()
    {
        $exp = new ParamException(Status::BadRequest);
        if (strlen($this->session->getId()) !== 40) {
            $exp->add('MerchantCode', '只允许APP登录');
            throw $exp;
        }
        $validator = new Validation();
        $validator->rules('MerchantCode', [
            new PresenceOf(['message' => '商户码不能为空']),
        ]);
        $validator->rules('Phone', [
            new PresenceOf(['message' => '手机号不能为空']),
            new Mobile(['message' => '请输入正确的手机号']),
        ]);
        $validator->rules('Password', [
            new PresenceOf(['message' => '密码不能为空']),
        ]);
        $ret = $validator->validate($this->request->getPost());
        if ($ret->count() > 0) {
            $exp->loadFromMessage($ret);
            throw $exp;
        }
        $user = User::findFirst([
            'conditions' => 'Phone=?0',
            'bind'       => [$this->request->getPost('Phone')],
        ]);
        if (!$user) {
            $exp->add('Phone', '账号或密码错误');
            throw $exp;
        }
        if (!$this->security->checkHash($this->request->getPost('Password'), $user->Password)) {
            $exp->add('Password', '账号或密码错误');

            throw $exp;
        }
        //所属机构
        $organizationUser = OrganizationUser::query()
            ->join("App\Models\Organization", 'O.Id=OrganizationId', 'O')
            ->where('UserId=' . $user->Id)
            ->andWhere('O.IsMain=2')
            ->execute();
        if (!$organizationUser) {
            $exp->add('MerchantCode', '云转诊app目前仅支持网点用户登录');
            throw $exp;
        }
        $upstream = Organization::findFirst([
            'conditions' => 'MerchantCode=?0',
            'bind'       => [$this->request->getPost('MerchantCode')],
        ]);
        if (!$upstream) {
            $exp->add('MerchantCode', '商户不存在');
            throw $exp;
        }
        //关系
        $ship = OrganizationRelationship::find([
            'conditions' => 'MainId=?0',
            'bind'       => [$upstream->Id],
        ]);
        if (!$ship) {
            $exp->add('MerchantCode', '商户号错误');
            throw $exp;
        }
        // todo: 同一个医院重复添加一个诊所
        $id = array_intersect(array_column($organizationUser->toArray(), 'OrganizationId'), array_column($ship->toArray(), 'MinorId'))[0];
        if (!$id) {
            $exp->add('MerchantCode', '商户号错误');
            throw $exp;
        }
        $org = Organization::findFirst($id);
        $realUser = OrganizationUser::findFirst([
            'conditions' => 'OrganizationId=?0 and UserId=?1',
            'bind'       => [$id, $user->Id],
        ]);
        if (!$realUser) {
            $exp->add('Phone', '用户出错');
            throw $exp;
        }
        //需要用到禁用时打开
        if ($realUser->UseStatus !== 1) {
            $exp->add('Password', '账户已被禁用');
            throw $exp;
        }
        $result = array_merge($user->toArray(), $realUser->toArray());
        $result['OrganizationId'] = $id;
        $result['Token'] = $this->session->getId();
        // 已登录app则挤掉
        $old = $this->redis->getSet(RedisName::Token . $user->Id, $result['Token']);
        if ($old) {
            $this->redis->del($old);
        }
        $realUser->LastLoginTime = time();
        $realUser->LastLoginIp = ip2long($this->request->getClientAddress());
        if ($realUser->save() === false) {
            $exp->loadFromModel($realUser);
            throw $exp;
        }

        $result['HospitalId'] = (null !== $upstream) ? $upstream->Id : $org->Id;
        $result['OrganizationName'] = $org->Name;
        $result['IsMain'] = $org->IsMain;
        $result['Verifyed'] = (null !== $upstream) ? $upstream->Verifyed : $org->Verifyed;
        $result['OrganizationPhone'] = $org->Phone;


        /**
         * @var  \Phalcon\Mvc\Model\Criteria $criteria
         */
        $criteria = Location::query();
        $criteria->inWhere('Id', [$org->ProvinceId, $org->CityId, $org->AreaId]);
        /**
         * @var \Phalcon\Mvc\Model\Resultset\Simple $locations
         */
        $locations = $criteria->execute();

        $result['HospitalName'] = (null !== $upstream) ? $upstream->Name : $org->Name;
        $result['HospitalId'] = (null !== $upstream) ? $upstream->Id : $org->Id;
        $result['OrganizationName'] = $org->Name;
        $result['ProvinceId'] = $org->ProvinceId;
        $result['CityId'] = $org->CityId;
        $result['AreaId'] = $org->AreaId;
        foreach ($locations as $location) {
            /**
             * @var Location $location
             */
            if ($location->Id === $org->ProvinceId) {
                $result['Province'] = $location->Name;
                continue;
            }
            if ($location->Id === $org->CityId) {
                $result['City'] = $location->Name;
                continue;
            }
            if ($location->Id === $org->AreaId) {
                $result['Area'] = $location->Name;
                continue;
            }
        }
        $result['Address'] = $org->Address;
        $result['Easemob'] = md5($user->Password);
        $result['MachineOrgId'] = $org->MachineOrgId;
        unset($result['Password']);
        $sendMessage = OrganizationSendMessageConfig::findFirst([
            'conditions' => 'OrganizationId=?0 and Type=?1',
            'bind'       => [$result['OrganizationId'], OrganizationSendMessageConfig::TYPE_SEND_TO_PATIENT],
        ]);
        $result['AgreeSendMessage'] = $sendMessage ? $sendMessage->AgreeSendMessage : OrganizationSendMessageConfig::AGREE_SEND_YES;


        $this->session->set('auth', $result);
        $this->response->setJsonContent($result);
    }

    public function logoutAction()
    {
        $reponse = new Response();
        $this->session->remove('auth');
        return $reponse;
    }

    /**
     * @Anonymous
     */
    public function forgetSmsAction()
    {
        $validation = new Validation();
        $validation->rules('Phone', [
            new PresenceOf(['message' => '请输入手机号']),
            new Mobile(['message' => '请输入正确的手机号']),
        ]);
        $validation->rules('MerchantCode', [
            new PresenceOf(['message' => '请输入商户号']),
        ]);
        $ex = new ParamException(Status::BadRequest);
        $ret = $validation->validate($this->request->get());
        if (count($ret) > 0) {
            $ex->loadFromMessage($ret);
            throw $ex;
        }
        $org = Organization::findFirst([
            'conditions' => 'MerchantCode=?0',
            'bind'       => [$this->request->get('MerchantCode')],
        ]);
        if (!$org) {
            $ex->add('MerchantCode', '错误的商户号');
            throw $ex;
        }
        $user = User::findFirst([
            'conditions' => 'Phone=?0',
            'bind'       => [$this->request->get('Phone')],
        ]);
        if (!$user) {
            $ex->add('Phone', '用户不存在');
            throw $ex;
        }
        $organizationUser = OrganizationUser::find([
            'conditions' => 'UserId=?0',
            'bind'       => [$user->Id],
        ])->toArray();
        $relation = OrganizationRelationship::query()
            ->inWhere('MinorId', array_column($organizationUser, 'OrganizationId'))
            ->andWhere(sprintf('MainId=%d', $org->Id))
            ->execute();
        if (!$relation) {
            throw new LogicException('商户与该手机未签约', Status::BadRequest);
        }
        $captcha = (string)random_int(100000, 999999);
        $content = MessageTemplate::load('captcha', MessageTemplate::METHOD_SMS, $captcha);
        $this->sms->send($this->request->get('Phone'), $content, 'forget', $captcha);
        $this->response->setJsonContent([
            'Token' => $this->session->getId(),
        ]);
    }

    /**
     * @Anonymous
     */
    public function forgetCheckAction()
    {
        $validation = new Validation();
        $validation->rules('Phone', [
            new PresenceOf(['message' => '请输入手机号']),
            new Mobile(['message' => '请输入正确的手机号']),
        ]);
        $validation->rules('MerchantCode', [
            new PresenceOf(['message' => '请输入商户号']),
        ]);
        $validation->rules('Captcha', [
            new PresenceOf(['message' => '请输入验证码']),
        ]);
        $ex = new ParamException(Status::BadRequest);
        $ret = $validation->validate($this->request->get());
        if (count($ret) > 0) {
            $ex->loadFromMessage($ret);
            throw $ex;
        }
        $org = Organization::findFirst([
            'conditions' => 'MerchantCode=?0',
            'bind'       => [$this->request->get('MerchantCode')],
        ]);
        if (!$org) {
            $ex->add('MerchantCode', '错误的商户号');
            throw $ex;
        }
        $user = User::findFirst([
            'conditions' => 'Phone=?0',
            'bind'       => [$this->request->get('Phone')],
        ]);
        if (!$user) {
            $ex->add('Phone', '用户不存在');
            throw $ex;
        }
        $organizationUser = OrganizationUser::find([
            'conditions' => 'UserId=?0',
            'bind'       => [$user->Id],
        ])->toArray();
        $relation = OrganizationRelationship::query()
            ->inWhere('MinorId', array_column($organizationUser, 'OrganizationId'))
            ->andWhere(sprintf('MainId=%d', $org->Id))
            ->execute();
        if (!count($relation->toArray())) {
            throw new LogicException('商户与该手机未签约', Status::BadRequest);
        }
        if ($this->sms->verify('forget', $this->request->get('Captcha')) === false) {
            $ex->add('Captcha', '验证码错误');
            throw $ex;
        }
        $token = md5(microtime(true));
        $key = sprintf(RedisName::RESET_PASSWORD_TOKEN, $token);

        $this->redis->setex($key, 300, $user->Id);
        $this->response->setJsonContent([
            'Token' => $token,
        ]);
    }

    /**
     * @Anonymous
     */
    public function editPasswordAction()
    {
        $validator = new Validation();
        $validator->rules('Password', [
            new PresenceOf(['message' => '请输入密码']),
            new Confirmation(['message' => '两次密码不一致', 'with' => 'RePassword']),
        ]);
        $validator->rules('RePassword', [
            new PresenceOf(['message' => '确认密码不能为空']),

        ]);
        $ex = new ParamException(Status::BadRequest);
        $ret = $validator->validate($this->request->getPost());
        if (count($ret) > 0) {
            $ex->loadFromMessage($ret);
            throw $ex;
        }
        $token = $this->request->get('Token');
        if (!$token) {
            throw new LogicException('非法提交', Status::BadRequest);
        }
        $key = sprintf(RedisName::RESET_PASSWORD_TOKEN, $token);
        $id = $this->redis->get($key);
        if (!$id) {
            throw new LogicException('页面已过期，请重新申请找回密码', Status::BadRequest);
        }
        $user = User::findFirst($id);
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
        $this->response->setJsonContent(['message' => '修改成功']);
    }

    /**
     * 获取个人信息
     */
    public function getConfigAction()
    {
        try {
            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException('请重新登录', Status::Unauthorized);
            }
            $this->response->setJsonContent($auth);
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 网点pc端登录
     * @Anonymous
     */
    public function SlaveAction()
    {
        $exp = new ParamException(Status::BadRequest);
        $validator = new Validation();
        $validator->rules('MerchantCode', [
            new PresenceOf(['message' => '商户码不能为空']),
        ]);
        $validator->rules('Phone', [
            new PresenceOf(['message' => '手机号不能为空']),
            new Mobile(['message' => '请输入正确的手机号']),
        ]);
        $validator->rules('Password', [
            new PresenceOf(['message' => '密码不能为空']),
        ]);
        $ret = $validator->validate($this->request->getPost());
        if ($ret->count() > 0) {
            $exp->loadFromMessage($ret);
            throw $exp;
        }
        $user = User::findFirst([
            'conditions' => 'Phone=?0',
            'bind'       => [$this->request->getPost('Phone')],
        ]);
        if (!$user) {
            $exp->add('Phone', '账号或密码错误');
            throw $exp;
        }
        if (!$this->security->checkHash($this->request->getPost('Password'), $user->Password)) {
            $exp->add('Password', '账号或密码错误');
            throw $exp;
        }
        //需要用到禁用时打开
        /*if ($user->UseStatus !== 1) {
            $exp->add('Password', '账户已被禁用');
            throw $exp;
        }*/

        //所属机构
        $organizationUser = OrganizationUser::query()
            ->join("App\Models\Organization", 'O.Id=OrganizationId', 'O')
            ->where('UserId=' . $user->Id)
            ->andWhere('O.IsMain=2')
            ->execute();
        if (!$organizationUser) {
            $exp->add('MerchantCode', '云转诊app目前仅支持网点用户登录');
            throw $exp;
        }
        $upstream = Organization::findFirst([
            'conditions' => 'MerchantCode=?0',
            'bind'       => [$this->request->getPost('MerchantCode')],
        ]);
        if (!$upstream) {
            $exp->add('MerchantCode', '商户不存在');
            throw $exp;
        }
        //关系
        $ship = OrganizationRelationship::find([
            'conditions' => 'MainId=?0',
            'bind'       => [$upstream->Id],
        ]);
        if (!$ship) {
            $exp->add('MerchantCode', '商户号错误');
            throw $exp;
        }
        $id = array_intersect(array_column($organizationUser->toArray(), 'OrganizationId'), array_column($ship->toArray(), 'MinorId'))[0];
        if (!$id) {
            $exp->add('MerchantCode', '商户号错误');
            throw $exp;
        }
        $org = Organization::findFirst($id);
        $realUser = OrganizationUser::findFirst([
            'conditions' => 'OrganizationId=?0 and UserId=?1',
            'bind'       => [$id, $user->Id],
        ]);
        if (!$realUser) {
            $exp->add('Phone', '用户出错');
            throw $exp;
        }
        $result = array_merge($user->toArray(), $realUser->toArray());
        $result['Token'] = $this->session->getId();
        $result['OrganizationId'] = $id;
        // 已登录PC则挤掉


        $realUser->LastLoginTime = time();
        $realUser->LastLoginIp = ip2long($this->request->getClientAddress());
        if ($realUser->save() === false) {
            $exp->loadFromModel($realUser);
            throw $exp;
        }

        $result['HospitalId'] = $upstream->Id;
        $result['OrganizationName'] = $org->Name;
        $result['IsMain'] = $org->IsMain;
        $result['Verifyed'] = $upstream->Verifyed;
        $result['OrganizationPhone'] = $org->Phone;
        $result['HospitalLogo'] = $upstream->Logo;

        /**
         * @var  \Phalcon\Mvc\Model\Criteria $criteria
         */
        $criteria = Location::query();
        $criteria->inWhere('Id', [$org->ProvinceId, $org->CityId, $org->AreaId]);
        /**
         * @var \Phalcon\Mvc\Model\Resultset\Simple $locations
         */
        $locations = $criteria->execute();

        $result['HospitalName'] = $upstream->Name;
        $result['HospitalId'] = $upstream->Id;
        $result['OrganizationName'] = $org->Name;
        $result['ProvinceId'] = $org->ProvinceId;
        $result['CityId'] = $org->CityId;
        $result['AreaId'] = $org->AreaId;
        foreach ($locations as $location) {
            /**
             * @var Location $location
             */
            if ($location->Id === $org->ProvinceId) {
                $result['Province'] = $location->Name;
                continue;
            }
            if ($location->Id === $org->CityId) {
                $result['City'] = $location->Name;
                continue;
            }
            if ($location->Id === $org->AreaId) {
                $result['Area'] = $location->Name;
                continue;
            }
        }
        $result['Address'] = $org->Address;
        $result['Easemob'] = md5($user->Password);
        unset($result['Password']);

        $this->session->set('auth', $result);
        $this->response->setJsonContent($result);
    }

    /**
     * 医院pc端登录
     * @Anonymous
     */
    public function hospitalAction()
    {
        $exp = new ParamException(Status::BadRequest);
        $validator = new Validation();
        $validator->rules('Phone', [
            new PresenceOf(['message' => '手机号不能为空']),
            new Mobile(['message' => '请输入正确的手机号']),
        ]);
        $validator->rules('Password', [
            new PresenceOf(['message' => '密码不能为空']),
        ]);
        $ret = $validator->validate($this->request->getPost());
        if ($ret->count() > 0) {
            $exp->loadFromMessage($ret);
            throw $exp;
        }
        $user = User::findFirst([
            'conditions' => 'Phone=?0',
            'bind'       => [$this->request->getPost('Phone')],
        ]);
        if (!$user) {
            $exp->add('Phone', '账号或密码错误');
            throw $exp;
        }
        if (!$this->security->checkHash($this->request->getPost('Password'), $user->Password)) {
            $exp->add('Password', '账号或密码错误');
            throw $exp;
        }
        $result = $user->toArray();
        $result['Token'] = $this->session->getId();

        //redis写入token
        $old = $this->redis->getSet(RedisName::TokenWeb . $user->Id, $result['Token']);
        if ($old) {
            $this->redis->del($old);
        }

        unset($result['Password']);
        $this->session->set('auth', $result);
        $this->response->setJsonContent($result);
    }

    /**
     * 查询该用户所属机构列表
     */
    public function organizationsAction()
    {
        $auth = $this->session->get('auth');
        if (!$auth) {
            return $this->response->setStatusCode(Status::Unauthorized);
        }
        $organizationUser = OrganizationUser::query()
            ->join("App\Models\Organization", 'O.Id=OrganizationId', 'O')
            ->where('UserId=:UserId:')
            ->andWhere(sprintf('UseStatus=%d', OrganizationUser::USESTATUS_ON))
            ->andWhere('O.IsMain!=:IsMain:')
            ->andWhere(sprintf('IsDelete=%d', OrganizationUser::IsDelete_No))
            ->bind(['UserId' => $auth['Id'], 'IsMain' => Organization::ISMAIN_SLAVE])
            ->execute();
        if (!$organizationUser->toArray()) {
            throw new LogicException('账号已被禁用', Status::BadRequest);
        }
        $organizations = Organization::query()
            ->columns(['Id', 'Name', 'Logo'])
            ->inWhere('Id', array_column($organizationUser->toArray(), 'OrganizationId'))
            ->execute();
        return $this->response->setJsonContent($organizations);
    }

    /**
     * 重新设置session
     */
    public function resetAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = $this->session->get('auth');
            if (!$auth) {
                return $this->response->setStatusCode(Status::Unauthorized);
            }
            $organizationId = (int)$this->request->get('Id', 'int');
            $org = Organization::findFirst($organizationId);
            $organizationUser = OrganizationUser::findFirst([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$organizationId, $auth['Id']],
            ]);
            if (!$org || !$organizationUser) {
                throw $exception;
            }
            if ($organizationUser->IsDelete == OrganizationUser::IsDelete_Yes) {
                $exception->add('Id', '账户不存在');
                throw $exception;
            }
            $organizationUser->LastLoginTime = time();
            $organizationUser->LastLoginIp = ip2long($this->request->getClientAddress());
            if ($organizationUser->save() === false) {
                $exception->loadFromModel($organizationUser);
                throw $exception;
            }
            if ($organizationUser->UseStatus !== 1) {
                $exception->add('Id', '账户已被禁用');
                throw $exception;
            }
            $auth = array_merge($auth, $organizationUser->toArray());
            $auth['OrganizationId'] = $organizationId;
            $auth['HospitalId'] = $org->Id;
            $auth['MerchantCode'] = $org->MerchantCode;
            $auth['IsMain'] = $org->IsMain;
            $auth['Logo'] = $org->Logo;
            $auth['OrganizationName'] = $org->Name;
            $auth['Verifyed'] = $org->Verifyed;
            $auth['OrganizationPhone'] = $org->Phone;
            $auth['HospitalName'] = $org->Name;
            $auth['HospitalId'] = $org->Id;
            $auth['OrganizationName'] = $org->Name;
            $auth['ProvinceId'] = $org->ProvinceId;
            $auth['CityId'] = $org->CityId;
            $auth['AreaId'] = $org->AreaId;
            $auth['Lat'] = $org->Lat;
            $auth['Lng'] = $org->Lng;
            $auth['OrgCreateTime'] = $org->CreateTime;
            /**
             * @var  \Phalcon\Mvc\Model\Criteria $criteria
             */
            $criteria = Location::query();
            $criteria->inWhere('Id', [$org->ProvinceId, $org->CityId, $org->AreaId]);
            /**
             * @var \Phalcon\Mvc\Model\Resultset\Simple $locations
             */
            $locations = $criteria->execute();
            foreach ($locations as $location) {
                /**
                 * @var Location $location
                 */
                if ($location->Id === $org->ProvinceId) {
                    $auth['Province'] = $location->Name;
                    continue;
                }
                if ($location->Id === $org->CityId) {
                    $auth['City'] = $location->Name;
                    continue;
                }
                if ($location->Id === $org->AreaId) {
                    $auth['Area'] = $location->Name;
                    continue;
                }
            }
            //认证的权限
            $illnessForDoctorIdentification = IllnessForDoctorIdentification::findFirst([
                'conditions' => 'UserId=?0',
                'bind'       => [$organizationUser->UserId],
            ]);
            $auth['Identification']['Hou'] = false;
            if ($illnessForDoctorIdentification) {
                $auth['Identification']['Hou'] = true;
            }
            $auth['Message'] = '';
            $expire = $org->Expire;
            //续费管理
            if ($auth['IsMain'] == Organization::ISMAIN_HOSPITAL) {
                $auth['Expire'] = date('Y年m月d日', strtotime($expire));
            }
            $auth['Address'] = $org->Address;
            $auth['MachineOrgId'] = $org->MachineOrgId;

            //电子签名
            $signature = UserSignature::findFirst([
                'conditions' => 'UserId=?0',
                'bind'       => [$organizationUser->UserId],
            ]);
            $auth['Signature'] = $signature ? true : false;

            $this->session->set('auth', $auth);

            return $this->response->setJsonContent($auth);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 登录之后修改用户密码
     */
    public function changePasswordAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $auth = $this->session->get('auth');
                if (!$auth) {
                    throw new LogicException('请登录', Status::Unauthorized);
                }
                $user = User::findFirst(sprintf('Id=%d', $auth['Id']));
                $oldPassword = $this->request->getPut('OldPassword');
                $password = $this->request->getPut('Password');
                $rePassword = $this->request->getPut('RePassword');
                if ($password !== $rePassword) {
                    $exception->add('RePassword', '两次密码输入不一致');
                    throw $exception;
                }
                if ($this->security->checkHash($oldPassword, $user->Password) === false) {
                    $exception->add('OldPassword', '原密码错误');
                    throw $exception;
                }
                $user->Password = $this->security->hash($password);
                //设置验证场景
                $user->setScene(User::SCENE_AUTH_EDIT);
                if ($user->save() === false) {
                    $exception->loadFromModel($user);
                    throw $exception;
                }
                $this->response->setStatusCode(Status::Created);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 获取 验证码
     * @Anonymous
     */
    public function authCodeAction()
    {
        $exp = new ParamException(Status::BadRequest);
        $validator = new Validation();
        $validator->rules('Phone', [
            new PresenceOf(['message' => '手机号不能为空']),
            new Mobile(['message' => '请输入正确的手机号']),
        ]);
        $ret = $validator->validate($this->request->get());
        if ($ret->count() > 0) {
            $exp->loadFromMessage($ret);
            throw $exp;
        }
        $phone = $this->request->getPost('Phone');
        $captcha = (string)random_int(1000, 9999);
        $content = MessageTemplate::load('captcha', MessageTemplate::METHOD_SMS, $captcha);
        $this->sms->send((string)$phone, $content, 'login', $captcha);
        $this->response->setJsonContent(['message' => '验证码发送成功', 'Token' => $this->session->getId()]);
    }

    /**
     * 验证 验证码
     * @Anonymous
     */
    public function verifyCodeAction()
    {
        $exp = new ParamException(Status::BadRequest);
        $validator = new Validation();
        $validator->rules('Phone', [
            new PresenceOf(['message' => '手机号不能为空']),
            new Mobile(['message' => '请输入正确的手机号']),
        ]);
        $validator->rules('Captcha', [
            new PresenceOf(['message' => '验证码不能为空']),
        ]);
        $ret = $validator->validate($this->request->get());
        if ($ret->count() > 0) {
            $exp->loadFromMessage($ret);
            throw $exp;
        }
        if (!$this->sms->verify('login', $this->request->get('Captcha'))) {
            $exp->add('Captcha', '手机号码或验证码错误');
            throw $exp;
        }

        /** @var User $user */
        $user = User::findFirst([
            'conditions' => 'Phone=?0',
            'bind'       => [$this->request->get('Phone')],
        ]);
        if (!$user) {
            $exp->add('Captcha', '手机号码或验证码错误');
            throw $exp;
        }
        $result = $user->toArray();
        unset($result['Password']);
        $result['Token'] = $this->session->getId();
        $this->session->set('auth', $user);
        $this->response->setJsonContent($result);
    }

    /**
     * 用户名密码登录验证
     * @Anonymous
     */
    public function userLoginAction()
    {
        $exp = new ParamException(Status::BadRequest);
        $validator = new Validation();
        $validator->rules('Phone', [
            new PresenceOf(['message' => '手机号不能为空']),
            new Mobile(['message' => '请输入正确的手机号']),
        ]);
        $validator->rules('Password', [
            new PresenceOf(['message' => '密码不能为空']),
        ]);
        $ret = $validator->validate($this->request->get());
        if ($ret->count() > 0) {
            $exp->loadFromMessage($ret);
            throw $exp;
        }

        /** @var User $user */
        $user = User::findFirst([
            'conditions' => 'Phone=?0',
            'bind'       => [$this->request->get('Phone')],
        ]);
        if (!$user) {
            $exp->add('Phone', '账号或密码错误');
            throw $exp;
        }
        if (!$this->security->checkHash($this->request->get('Password'), $user->Password)) {
            $exp->add('Password', '账号或密码错误');
            throw $exp;
        }
        $result = $user->toArray();
        unset($result['Password']);
        $result['Token'] = $this->session->getId();
        $this->session->set('auth', $user);
        $this->response->setJsonContent($result);
    }

    /**
     * 获取网点所属的医院
     * @Anonymous
     */
    public function getSlaveHospitalsAction()
    {
        $auth = $this->session->get('auth');
        if (!$auth) {
            throw new LogicException("验证未通过或已过期", Status::BadRequest);
        }
        $organizationUser = OrganizationUser::query()
            ->leftJoin(Organization::class, 'O.Id=OrganizationId', 'O')
            ->where('UserId=:UserId:')
            ->andWhere('O.IsMain=2')
            ->andWhere('UseStatus=:UseStatus:')
            ->bind(['UserId' => $auth['Id'], 'UseStatus' => OrganizationUser::USESTATUS_ON])
            ->execute()->toArray();

        if (!$organizationUser) {
            throw new LogicException('账号不存在或被禁用', Status::BadRequest);
        }
        $organizations = OrganizationRelationship::query()
            ->columns(['O.Id', 'O.Name', 'O.Logo', 'MinorId'])
            ->leftJoin(Organization::class, 'O.Id=MainId', 'O')
            ->inWhere('MinorId', array_column($organizationUser, 'OrganizationId'))
            ->execute();
        $tmp = [];
        if (count($organizations->toArray())) {
            foreach ($organizations as $organization) {
                foreach ($organizationUser as $item) {
                    if ($organization->MinorId == $item['OrganizationId']) {
                        $tmp[$organization->Id] = $item['OrganizationId'];
                    }
                }
            }
        }
        $auth['organizationUser'] = $tmp;
        $this->session->set('auth', $auth);
        $this->response->setJsonContent($organizations);
    }

    /**
     * 登录
     */
    public function slaveResetAction()
    {
        $exp = new ParamException(Status::BadRequest);
        try {
            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException("验证未通过或已过期", Status::BadRequest);
            }
            $hospitalId = (int)$this->request->get('Id', 'int');
            //所属机构
            $organizationId = $auth['organizationUser'][$hospitalId];
            unset($auth['organizationUser']);
            /** @var OrganizationUser $organizationUser */
            $organizationUser = OrganizationUser::findFirst([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$organizationId, $auth['Id']],
            ]);


            /** @var Organization $upstream */
            $upstream = Organization::findFirst([
                'conditions' => 'Id=?0',
                'bind'       => [$hospitalId],
            ]);

            /** @var Organization $org */
            $org = Organization::findFirst($organizationUser->OrganizationId);

            $result = array_merge($auth, $organizationUser->toArray());
            $result['Token'] = $this->session->getId();
            // 已登录app则挤掉
            $old = $this->redis->getSet(RedisName::Token . $auth['Id'], $result['Token']);
            if ($old) {
                $this->redis->del($old);
            }
            $organizationUser->LastLoginTime = time();
            $organizationUser->LastLoginIp = ip2long($this->request->getClientAddress());
            if ($organizationUser->save() === false) {
                $exp->loadFromModel($organizationUser);
                throw $exp;
            }

            $result['HospitalId'] = (null !== $upstream) ? $upstream->Id : $org->Id;
            $result['OrganizationName'] = $org->Name;
            $result['IsMain'] = $org->IsMain;
            $result['Verifyed'] = (null !== $upstream) ? $upstream->Verifyed : $org->Verifyed;
            $result['OrganizationPhone'] = $org->Phone;


            /**
             * @var  \Phalcon\Mvc\Model\Criteria $criteria
             */
            $criteria = Location::query();
            $criteria->inWhere('Id', [$org->ProvinceId, $org->CityId, $org->AreaId]);
            /**
             * @var \Phalcon\Mvc\Model\Resultset\Simple $locations
             */
            $locations = $criteria->execute();

            $result['HospitalName'] = (null !== $upstream) ? $upstream->Name : $org->Name;
            $result['HospitalId'] = (null !== $upstream) ? $upstream->Id : $org->Id;
            $result['OrganizationName'] = $org->Name;
            $result['ProvinceId'] = $org->ProvinceId;
            $result['CityId'] = $org->CityId;
            $result['AreaId'] = $org->AreaId;
            foreach ($locations as $location) {
                /**
                 * @var Location $location
                 */
                if ($location->Id === $org->ProvinceId) {
                    $result['Province'] = $location->Name;
                    continue;
                }
                if ($location->Id === $org->CityId) {
                    $result['City'] = $location->Name;
                    continue;
                }
                if ($location->Id === $org->AreaId) {
                    $result['Area'] = $location->Name;
                    continue;
                }
            }
            $result['Address'] = $org->Address;
            $result['MachineOrgId'] = $org->MachineOrgId;
            unset($result['Password']);
            $sendMessage = OrganizationSendMessageConfig::findFirst([
                'conditions' => 'OrganizationId=?0 and Type=?1',
                'bind'       => [$result['OrganizationId'], OrganizationSendMessageConfig::TYPE_SEND_TO_PATIENT],
            ]);
            $result['AgreeSendMessage'] = $sendMessage ? $sendMessage->AgreeSendMessage : OrganizationSendMessageConfig::AGREE_SEND_YES;
            $this->session->set('auth', $result);
            $this->response->setJsonContent($result);
        } catch (ParamException $e) {
            throw $e;
        }
    }
}
