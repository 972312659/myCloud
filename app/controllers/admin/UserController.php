<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/9/16
 * Time: 20:45
 */

namespace App\Admin\Controllers;


use App\Enums\RedisName;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\Staff;
use Phalcon\Http\Response;
use Phalcon\Validation;
use App\Validators\Mobile;
use Phalcon\Validation\Validator\PresenceOf;

class UserController extends Controller
{
    public function setUserPasswordAction()
    {
        $user = User::findFirstByPhone($this->request->getPost('Phone'));
        if (!$user || !$this->request->getPost('Password')) {
            throw new LogicException('参数错误', Status::BadRequest);
        }
        $user->Password = $this->security->hash($this->request->getPost('Password'));
        if ($user->save() === false) {
            $exp = new ParamException(Status::BadRequest);
            $exp->loadFromModel($user);
        }
    }

    /**
     * @Anonymous
     * @throws ParamException
     */
    public function loginAction()
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
        $user = Staff::findFirst([
            'conditions' => 'Phone=?0',
            'bind'       => [$this->request->getPost('Phone')],
        ]);
        if (!$user) {
            $exp->add('Phone', '手机号码错误');
            throw $exp;
        }
        if (!$this->security->checkHash($this->request->getPost('Password'), $user->Password)) {
            $exp->add('Password', '密码错误');
            throw $exp;
        }

        $result = $user->toArray();
        $resource = $this->modelsManager->createBuilder()
            ->columns('P.Resource')
            ->addFrom(RolePermission::class, 'RP')
            ->join(Permission::class, 'P.Id=RP.PermissionId', 'P', 'left')
            ->where('RP.RoleId=?0', [$user->Role])
            ->getQuery()->execute()->toArray();
        $resource = array_column($resource, 'Resource');
        $this->redis->set(RedisName::Staff . $user->Id, json_encode($resource));
        $resource_new = [];
        if (count($resource)) {
            foreach ($resource as $v) {
                $resource_new[$v] = true;
            }
        }
        $result['Permission'] = $resource_new;
        $result['Token'] = $this->session->getId();
        unset($result['Password']);
        $this->session->set('auth', $result);
        $this->response->setJsonContent($result);
    }

    public function logoutAction()
    {
        $reponse = new Response();
        $this->session->remove('auth');
        return $reponse;
    }

    public function editAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException('请登录', Status::Unauthorized);
            }
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPut();
            if (isset($data['Password']) && !empty($data['Password']) && isset($data['RePassword']) && !empty($data['RePassword'])) {
                if ($data['Password'] !== $data['RePassword']) {
                    $exception->add('RePassword', '两次输入密码不一致');
                    throw $exception;
                }
            }
            $staff = Staff::findFirst(sprintf('Id=%d', $auth['Id']));
            if (!$staff) {
                throw $exception;
            }
            if (isset($data['Password']) && !empty($data['Password']) && isset($data['OldPassword']) && !empty($data['OldPassword']) && isset($data['RePassword']) && !empty($data['RePassword'])) {
                if (!$this->security->checkHash($data['OldPassword'], $staff->Password)) {
                    $exception->add('Password', '密码错误');
                    throw $exception;
                }
                $data['Password'] = $this->security->hash($data['Password']);
            } else {
                unset($data['Password']);
            }
            if ($staff->save($data) === false) {
                $exception->loadFromModel($staff);
                throw $exception;
            }
            $this->response->setStatusCode(Status::Created);
            return $this->response->setJsonContent($staff);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }
}