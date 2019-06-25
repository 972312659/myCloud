<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/25
 * Time: 上午11:43
 * 员工
 */

namespace App\Admin\Controllers;

use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\ArrayIsSame;
use App\Libs\CompanyWechat;
use App\Models\Staff;
use App\Models\StaffDepartment;
use App\Models\WechatDepartment;
use Phalcon\Paginator\Adapter\QueryBuilder;

class StaffController extends Controller
{
    /**
     * 创建员工 修改员工
     */
    public function addStaffAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            $wechat = new CompanyWechat();
            if ($this->request->isPost()) {
                $staff = new Staff();
                $data = $this->request->getPost();
                $data['Created'] = time();
                if (empty($data['Password']) || !isset($data['Password'])) {
                    $data['Password'] = Staff::DEFAULT_PASSWORD;
                }
                $data['Password'] = $this->security->hash($data['Password']);
                //创建企业微信员工
                $create = true;
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $staff = Staff::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$staff) {
                    throw $exception;
                }
                if ($staff->Id === Staff::ADMINISTRATION) {
                    if ($this->staff->Id !== Staff::ADMINISTRATION) {
                        throw new LogicException('无权修改', Status::Forbidden);
                    }
                }
                unset($data['Password']);
                //修改企业微信员工
                $create = false;
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data['Name'] = trim($data['Name']);
            $data['userid'] = trim($data['userid']);
            //企业微信
            if (!preg_match('/^[a-zA-Z0-9._\-]{1,32}$/', $data['userid'])) {
                $exception->add('userid', '必须是1-32位英文字母');
                throw $exception;
            }
            if (strlen($data['Name']) > 32) {
                $exception->add('Name', '长度不应大于32');
                throw $exception;
            }
            if (!is_array($data['department']) || !isset($data['department']) || empty($data['department']) || !$data['department']) {
                $data['department'] = [WechatDepartment::ROOT];
            }
            if (!preg_match('/^1[345789]\d{9}$/', $data['Phone'])) {
                $exception->add('mobile', '无效的手机号码');
                throw $exception;
            }
            if ($this->request->isPost()) {
                if ($wechat->userRead($data['userid'])) {
                    $exception->add('userid', '该账户已被注册');
                    throw $exception;
                }
                $wechat->createUser($data['userid'], $data['Name'], $data['Phone'], $data['department'], $create);
            } elseif ($this->request->isPut()) {
                if (!$wechat->userRead($data['userid'])) {
                    if ($staff->WechartUserId != $data['userid']) {
                        if ($wechat->userRead($data['userid'])) {
                            $exception->add('userid', '该账户已被注册');
                            throw $exception;
                        }
                        //删除以前的
                        if ($staff->WechartUserId) {
                            if ($wechat->userRead($staff->WechartUserId)) {
                                $wechat->delUser($staff->WechartUserId);
                            }
                        }
                    }
                    $wechat->createUser($data['userid'], $data['Name'], $data['Phone'], $data['department'], true);
                } else {
                    $wechat->createUser($data['userid'], $data['Name'], $data['Phone'], $data['department'], $create);
                }
            }
            $data['WechartUserId'] = $data['userid'];
            $staff->setScene(Staff::SCENE_STAFF_ADDSTAFF);
            if ($staff->save($data) === false) {
                $exception->loadFromModel($staff);
                throw $exception;
            }
            //关联员工和部门
            $staffDepartment = StaffDepartment::find([
                'conditions' => 'StaffId=?0',
                'bind'       => [$staff->Id],
            ]);
            if (count($staffDepartment)) {
                $departments = array_column($staffDepartment->toArray(), 'DepartmentId');
                if (!ArrayIsSame::issame($data['department'], $departments)) {
                    foreach ($staffDepartment as $item) {
                        if (!in_array($item->DepartmentId, $data['department'])) {
                            $item->delete();
                        }
                        foreach ($data['department'] as $datum) {
                            if (!in_array($datum, $departments)) {
                                $department = new StaffDepartment();
                                $department->StaffId = $staff->Id;
                                $department->DepartmentId = $datum;
                                $department->Switch = StaffDepartment::SWITCH_ON;
                                if ($department->save() === false) {
                                    $exception->loadFromModel($department);
                                    throw $exception;
                                }
                            }
                        }
                    }
                }
            } else {
                foreach ($data['department'] as $datum) {
                    $department = new StaffDepartment();
                    $department->StaffId = $staff->Id;
                    $department->DepartmentId = $datum;
                    $department->Switch = StaffDepartment::SWITCH_ON;
                    if ($department->save() === false) {
                        $exception->loadFromModel($department);
                        throw $exception;
                    }
                }
            }
            $this->db->commit();
            $result = $staff->toArray();
            unset($result['Password']);
            $this->response->setJsonContent($result);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function readAction()
    {
        $staff = Staff::findFirst($this->request->get('Id'));
        if (!$staff) {
            throw new LogicException('员工不存在', Status::BadRequest);
        }
        $this->response->setJsonContent($staff);
    }

    public function delAction()
    {
        $id = $this->request->get('Id');
        $staff = Staff::findFirst(sprintf('Id=%d', $id));
        if (!$staff) {
            throw new ParamException(Status::BadRequest);
        }
        $userid = $staff->WechartUserId;
        if ($staff->delete()) {
            //删除企业微信中的人员
            $wechat = new CompanyWechat();
            $wechat->delUser($userid);
            $staffDepartment = StaffDepartment::find(sprintf('StaffId=%d', $id));
            if (count($staffDepartment)) {
                $staffDepartment->delete();
            }
        }
    }

    public function listAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = Staff::query();
        $paginator = new QueryBuilder(
            [
                "builder" => $query->createBuilder(),
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $this->outputPagedJson($paginator);
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

    /**
     * 更新企业微信的部门数据
     */
    public function refreshPartmentAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            $wechat = new CompanyWechat();
            $departments = $wechat->partyList();
            $oldDepartment = WechatDepartment::find();
            if ($oldDepartment) {
                $oldDepartment->delete();
            }
            if ($departments) {
                foreach ($departments as $department) {
                    $wechatDepartment = new WechatDepartment();
                    $wechatDepartment->Id = $department['id'];
                    $wechatDepartment->Name = $department['name'];
                    if ($wechatDepartment->save() === false) {
                        $exception->loadFromModel($wechatDepartment);
                        throw $exception;
                    }
                }
            }
            $this->db->commit();
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 更新企业微信人员，将接收消息权限都设置为接收
     */
    public function refreshAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $wechat = new CompanyWechat();
            $users = $wechat->userList(WechatDepartment::ROOT);
            $users_new = [];
            if ($users) {
                foreach ($users as $user) {
                    $users_new[$user['name']] = ['userid' => $user['userid'], 'department' => $user['department']];
                }
            }
            $staffs = Staff::find();
            $this->db->begin();
            if ($staffs) {
                if ($this->request->get('Style') == 'New') {
                    foreach ($staffs as $staff) {
                        if (!$staff->WechartUserId) {
                            if (isset($users_new[$staff->Name])) {
                                $userid = $users_new[$staff->Name]['userid'];
                                $departments = $users_new[$staff->Name]['department'];
                                $staff->WechartUserId = $userid;
                                if ($staff->save() === false) {
                                    $exception->loadFromModel($staff);
                                    throw $exception;
                                }
                                if ($departments) {
                                    foreach ($departments as $department) {
                                        $staffDepartment = new StaffDepartment();
                                        $staffDepartment->StaffId = $staff->Id;
                                        $staffDepartment->DepartmentId = $department;
                                        $staffDepartment->Switch = StaffDepartment::SWITCH_ON;
                                        if ($staffDepartment->save() === false) {
                                            $exception->loadFromModel($staffDepartment);
                                            throw $exception;
                                        }
                                    }
                                }
                            }
                        }
                    }
                } else {
                    foreach ($staffs as $staff) {
                        if (isset($users_new[$staff->Name])) {
                            $userid = $users_new[$staff->Name]['userid'];
                            $departments = $users_new[$staff->Name]['department'];
                            $staff->WechartUserId = $userid;
                            if ($staff->save() === false) {
                                $exception->loadFromModel($staff);
                                throw $exception;
                            }
                            if ($departments) {
                                foreach ($departments as $department) {
                                    $staffDepartment = new StaffDepartment();
                                    $staffDepartment->StaffId = $staff->Id;
                                    $staffDepartment->DepartmentId = $department;
                                    $staffDepartment->Switch = StaffDepartment::SWITCH_ON;
                                    if ($staffDepartment->save() === false) {
                                        $exception->loadFromModel($staffDepartment);
                                        throw $exception;
                                    }
                                }
                            }
                        }
                    }
                }

            }
            $this->db->commit();
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 该员工所在部门消息权限列表
     */
    public function departmentAction()
    {
        $staffs = StaffDepartment::find([
            'conditions' => 'StaffId=?0',
            'bind'       => [$this->request->get('StaffId')],
        ])->toArray();
        $departments = WechatDepartment::query()->inWhere('Id', array_column($staffs, 'DepartmentId'))->execute();
        $department_new = [];
        if ($departments) {
            foreach ($departments as $department) {
                $department_new[$department->Id] = $department->Name;
            }
        }
        if ($staffs) {
            foreach ($staffs as &$staff) {
                $staff['DepartmentName'] = $department_new[$staff['DepartmentId']];
            }
        }
        $this->response->setJsonContent($staffs);
    }

    /**
     * 消息开关
     */
    public function switchAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $staff = StaffDepartment::findFirst([
                'conditions' => 'StaffId=?0 and DepartmentId=?1',
                'bind'       => [$this->request->getPut('StaffId'), $this->request->getPut('DepartmentId')],
            ]);
            if (!$staff) {
                throw $exception;
            }
            $staff->Switch = StaffDepartment::SWITCH_ON ? StaffDepartment::SWITCH_OFF : StaffDepartment::SWITCH_ON;
            if ($staff->save() === false) {
                $exception->loadFromModel($staff);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 本地部门列表
     */
    public function departmentListAction()
    {
        $department = WechatDepartment::find()->toArray();
        $default = StaffDepartment::find([
            'conditions' => 'StaffId=?0',
            'bind'       => [$this->request->get('StaffId')],
        ]);
        $default_new = [];
        if ($default) {
            foreach ($default as $item) {
                $default_new[$item->DepartmentId] = 1;
            }
        }
        if ($department) {
            foreach ($department as &$item) {
                $item['Default'] = $default_new[$item['Id']];
            }
        }
        array_shift($department);
        $this->response->setJsonContent($department);
    }
}