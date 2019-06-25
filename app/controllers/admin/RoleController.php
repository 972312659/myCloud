<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/21
 * Time: 上午11:36
 */

namespace App\Admin\Controllers;


use App\Enums\RedisName;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Staff;
use Phalcon\Paginator\Adapter\QueryBuilder;

class RoleController extends Controller
{
    /**
     * 创建角色
     */
    public function roleAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $data['OrganizationId'] = Organization::PEACH;
                $data['Remark'] = Role::STAFF_ADMIN_REMOTE;
                $role = new Role();
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                if ($data['Id'] == Role::DEFAULT_B || $data['Id'] == Role::DEFAULT_b) {
                    throw new LogicException('无权操作', Status::Forbidden);
                }
                //超级管理员权限不能修改
                $admin = Staff::findFirst(Staff::ADMINISTRATION);
                if ($admin) {
                    if ($admin->Role == $data['Id']) {
                        if ($admin->Id != $this->session->get('auth')['Id']) {
                            throw new LogicException('无权操作', Status::Forbidden);
                        }
                    }
                }
                $role = Role::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$role) {
                    throw $exception;
                }
                $staffs = Staff::find(sprintf('Role=%d', $role->Id));
                if ($staffs) {
                    foreach ($staffs as $staff) {
                        $staff->Role = 0;
                        if ($staff->save() === false) {
                            $exception->loadFromModel($staff);
                            throw $exception;
                        }
                        $this->redis->delete(RedisName::Staff . $staff->Id);
                    }
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if ($role->save($data) === false) {
                $exception->loadFromModel($role);
                throw $exception;
            }
            if (!isset($data['Permissions']) || !is_array($data['Permissions'])) {
                throw new LogicException('Permissions参数错误', Status::BadRequest);
            }
            if (!isset($data['StaffIds']) || !is_array($data['StaffIds'])) {
                throw new LogicException('StaffIds参数错误', Status::BadRequest);
            }
            $rolePermission = RolePermission::find(["conditions" => "RoleId=:RoleId:", "bind" => ["RoleId" => $role->Id]]);
            if ($rolePermission) {
                if ($rolePermission->delete() === false) {
                    throw $exception;
                }
            }
            if (!empty($data['Permissions'])) {
                foreach ($data['Permissions'] as $v) {
                    $_rolePermission = new RolePermission();
                    $info['RoleId'] = $role->Id;
                    $info['PermissionId'] = $v;
                    if ($_rolePermission->save($info) === false) {
                        $exception->loadFromModel($_rolePermission);
                        throw $exception;
                    }
                }
            }
            if (!empty($data['StaffIds'])) {
                $staffs = Staff::query()->inWhere('Id', $data['StaffIds'])->execute();
                if (!$staffs) {
                    throw $exception;
                }
                foreach ($staffs as $staff) {
                    $staff->Role = $role->Id;
                    if ($staff->save() === false) {
                        $exception->loadFromModel($staff);
                        throw $exception;
                    }
                    $this->redis->delete(RedisName::Staff . $staff->Id);
                }
            }
            $this->db->commit();
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 删除角色
     */
    public function deleteRoleAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isDelete()) {
                $id = $this->request->getPut('Id');
                if ($id == Role::DEFAULT_B || $id == Role::DEFAULT_b) {
                    throw new LogicException('无权操作', Status::Forbidden);
                }
                //超级管理员权限不能删除
                $admin = Staff::findFirst(Staff::ADMINISTRATION);
                if ($admin) {
                    if ($admin->Role == $id) {
                        throw new LogicException('无权操作', Status::Forbidden);
                    }
                }
                $this->db->begin();
                $role = Role::findFirst(sprintf('Id=%d', $id));
                if (!$role) {
                    throw $exception;
                }
                $rolePermission = RolePermission::find(["conditions" => "RoleId=:RoleId:", "bind" => ["RoleId" => $role->Id]]);
                if ($rolePermission) {
                    if ($rolePermission->delete() === false) {
                        throw $exception;
                    }
                }
                $staffs = Staff::find(sprintf('Role=%d', $role->Id));
                if ($staffs) {
                    foreach ($staffs->toArray() as $v) {
                        $this->redis->delete(RedisName::Staff . $v['Id']);
                    }
                }
                if ($role->delete() === false) {
                    throw $exception;
                }
                $this->db->commit();
                $this->response->setJsonContent(['message' => '成功']);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch
        (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 资源列表
     */
    public function permissionListAction()
    {
        $permissions = Permission::find(['conditions' => 'Visiable=?0', 'bind' => [Permission::VISIABLE_ADMIN]]);
        $this->response->setJsonContent($permissions);
    }

    /**
     * 角色列表
     */
    public function roleListAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = Role::query()
            ->where(sprintf('OrganizationId=%d', Organization::PEACH))
            ->andWhere("Remark=" . Role::STAFF_ADMIN_REMOTE)
            ->notInWhere('Id', [Role::DEFAULT_B, Role::DEFAULT_b]);
        $paginator = new QueryBuilder(
            [
                "builder" => $query->createBuilder(),
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        if ($datas) {
            $roles = $this->modelsManager->createBuilder()
                ->columns(['RP.RoleId', 'P.Id', 'P.Name'])
                ->addFrom(RolePermission::class, 'RP')
                ->join(Permission::class, 'P.Id=RP.PermissionId', 'P', 'left')
                ->inWhere('RoleId', array_column($datas, 'Id'))
                ->getQuery()
                ->execute();
            $roles_new = [];
            if ($roles) {
                foreach ($roles as $role) {
                    $roles_new[$role->RoleId][] = ['Id' => $role->Id, 'Name' => $role->Name];
                }
            }
            foreach ($datas as &$data) {
                $data['Permissions'] = $roles_new[$data['Id']];
            }
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 员工列表
     */
    public function staffListAction()
    {
        $staffs = Staff::find();
        $result = [];
        if ($staffs) {
            $result = $staffs->toArray();
            foreach ($result as &$item) {
                unset($item['Password']);
            }
        }
        $this->response->setJsonContent($result);
    }
}