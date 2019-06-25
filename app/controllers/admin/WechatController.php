<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/12/13
 * Time: 上午10:43
 */

namespace App\Admin\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\CompanyWechat;
use App\Models\StaffDepartment;
use App\Models\WechatDepartment;

/**
 * Class WechatController
 * @package App\Admin\Controllers
 * @property \App\Libs\CompanyWechat $wechat
 */
class WechatController extends Controller
{
    /**
     * @var object
     */
    private $wechat = object;

    public function onConstruct()
    {
        $this->wechat = new CompanyWechat();
    }

    /**
     * 创建 修改部门
     * @return array|mixed|object|\stdClass
     * @throws LogicException
     * @throws ParamException
     */
    public function createPartyAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $data['id'] = null;
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if (strlen($data['name']) > 32) {
                $exception->add('name', '长度不应大于32');
                throw $exception;
            }
            if (empty($data['parentid']) || !isset($data['parentid'])) {
                $data['parentid'] = WechatDepartment::ROOT;
            }
            $id = $this->wechat->createParty($data['name'], $data['parentid'], $data['id']);
            if ($this->request->isPost()) {
                $department = new WechatDepartment();
                $department->Id = $id;
                $department->Name = $data['name'];
            } elseif ($this->request->isPut()) {
                $department = WechatDepartment::findFirst(sprintf('Id=%d', $data['id']));
                $department->Name = $data['name'];
            }
            if ($department->save() === false) {
                $exception->loadFromModel($department);
                throw $exception;
            }
        } catch (LogicException $e) {
            throw $e;
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 删除部门
     */
    public function delPartyAction()
    {
        if (!$this->request->isDelete()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }
        $id = $this->request->getPut('id', 'int');
        if ($this->wechat->delParty($id)) {
            WechatDepartment::findFirst($id)->delete();
            $staffdepartment = StaffDepartment::find([
                'conditions' => 'DepartmentId=?0',
                'bind'       => [$id],
            ]);
            if ($staffdepartment) {
                $staffdepartment->delete();
            }
            return true;
        } else {
            return false;
        }
    }

    /**
     * 部门列表
     * @return \Phalcon\Http\Response
     */
    public function partyListAction()
    {
        $partys = $this->wechat->partyList($this->request->get('id', 'int'));
        $partys_new = [];
        if ($partys) {
            foreach ($partys as $party) {
                $partys_new[$party['parentid']][] = $party;
            }
            foreach ($partys as $k => &$party) {
                $party['children'] = $partys_new[$party['id']];
                if ($party['parentid'] != 0) {
                    unset($partys[$k]);
                }
                if ($party['children']) {
                    foreach ($party['children'] as &$child) {
                        $child['children'] = $partys_new[$child['id']];
                    }
                }
            }
        }
        return $this->response->setJsonContent($partys);

    }

    /**
     * 创建 修改成员
     * @param array $data ['department']
     * @return bool
     * @throws LogicException
     * @throws ParamException
     */
    public function createUserAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $create = true;
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $create = false;
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if (strlen($data['userid']) > 32) {
                $exception->add('userid', '长度不应大于32');
                throw $exception;
            }
            if (strlen($data['name']) > 32) {
                $exception->add('name', '长度不应大于32');
                throw $exception;
            }
            if (!is_array($data['department'])) {
                throw $exception;
            }
            if (!preg_match('/^1[345789]\d{9}$/', $data['mobile'])) {
                $exception->add('mobile', '无效的手机号码');
                throw $exception;
            }
            return $this->wechat->createUser($data['userid'], $data['name'], $data['mobile'], $data['department'], $create);
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 查看详情
     * @return array|mixed|object|\stdClass
     */
    public function userReadAction()
    {
        return $this->response->setJsonContent($this->wechat->userRead($this->request->get('userid')));
    }

    /**
     * 获取当前部门下的所有人员
     * @return \Phalcon\Http\Response
     */
    public function userListAction()
    {
        return $this->response->setJsonContent($this->wechat->userList($this->request->get('department_id')));
    }

    public function sendMessageAction()
    {
        $content = '苟哥好jb帅';
        $this->wechat->send($content, [1], ['goujun']);
    }

}