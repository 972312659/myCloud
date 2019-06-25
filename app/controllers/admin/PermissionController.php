<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/12/6
 * Time: 下午3:18
 */

namespace App\Admin\Controllers;

use App\Enums\RedisName;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\OrganizationUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Staff;
use Phalcon\Paginator\Adapter\QueryBuilder;

class PermissionController extends Controller
{
    /**
     * 资源创建与修改
     */
    public function permissionAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $permission = new Permission();
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $permission = Permission::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$permission) {
                    throw $exception;
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $default = 0;
            if (is_array($data['Default']) && count($data['Default'])) {
                foreach ($data['Default'] as $datum) {
                    $default = $default | $datum;
                }
                $data['Default'] = $default;
            } else {
                $data['Default'] = 0;
            }
            if ($permission->save($data) === false) {
                $exception->loadFromModel($permission);
                throw $exception;
            }
            switch ($permission->Visiable) {
                case Permission::VISIABLE_OUTSIDE:
                    $roles = [];
                    $users = [];
                    $orgUsers_B = OrganizationUser::find([
                        'conditions' => "Role=:Role:",
                        "bind"       => ["Role" => Role::DEFAULT_B],
                    ])->toArray();
                    $orgUsers_b = OrganizationUser::find([
                        'conditions' => "Role=:Role:",
                        "bind"       => ["Role" => Role::DEFAULT_b],
                    ])->toArray();
                    $orgUsers_s = OrganizationUser::find([
                        'conditions' => "Role=:Role:",
                        "bind"       => ["Role" => Role::DEFAULT_SUPPLIER],
                    ])->toArray();
                    if ($this->request->isPost()) {
                        if ($default & Permission::DEFAULT_HOSPITAL) {
                            $roles[] = Role::DEFAULT_B;
                            if (count($orgUsers_B)) {
                                $users = array_merge($users, $orgUsers_B);
                            }
                        }
                        if ($default & Permission::DEFAULT_SLAVE) {
                            $roles[] = Role::DEFAULT_b;
                            if (count($orgUsers_b)) {
                                $users = array_merge($users, $orgUsers_b);
                            }
                        }
                        if ($default & Permission::DEFAULT_SUPPLIER) {
                            $roles[] = Role::DEFAULT_SUPPLIER;
                            if (count($orgUsers_s)) {
                                $users = array_merge($users, $orgUsers_s);
                            }
                        }
                    } elseif ($this->request->isPut()) {
                        $oldRole_B = RolePermission::findFirst(['conditions' => 'PermissionId=?0 and RoleId=?1', 'bind' => [$permission->Id, Role::DEFAULT_B]]);
                        if ($default & Permission::DEFAULT_HOSPITAL) {
                            if (!$oldRole_B) {
                                $roles[] = Role::DEFAULT_B;
                                $users = array_merge($users, $orgUsers_B);
                            }
                        } else {
                            if ($oldRole_B) {
                                $oldRole_B->delete();
                                $users = array_merge($users, $orgUsers_B);
                            }
                        }
                        $oldRole_b = RolePermission::findFirst(['conditions' => 'PermissionId=?0 and RoleId=?1', 'bind' => [$permission->Id, Role::DEFAULT_b]]);
                        if ($default & Permission::DEFAULT_SLAVE) {
                            if (!$oldRole_b) {
                                $roles[] = Role::DEFAULT_b;
                                $users = array_merge($users, $orgUsers_b);
                            }
                        } else {
                            if ($oldRole_b) {
                                $oldRole_b->delete();
                                $users = array_merge($users, $orgUsers_b);
                            }
                        }
                        $oldRole_s = RolePermission::findFirst(['conditions' => 'PermissionId=?0 and RoleId=?1', 'bind' => [$permission->Id, Role::DEFAULT_SUPPLIER]]);
                        if ($default & Permission::DEFAULT_SUPPLIER) {
                            if (!$oldRole_s) {
                                $roles[] = Role::DEFAULT_SUPPLIER;
                                $users = array_merge($users, $orgUsers_s);
                            }
                        } else {
                            if ($oldRole_s) {
                                $oldRole_s->delete();
                                $users = array_merge($users, $orgUsers_s);
                            }
                        }
                    }
                    if (count($roles)) {
                        foreach ($roles as $role) {
                            $rolePermission = new RolePermission();
                            $rolePermission->RoleId = $role;
                            $rolePermission->PermissionId = $permission->Id;
                            if ($rolePermission->save() === false) {
                                $exception->loadFromModel($rolePermission);
                                throw $exception;
                            }
                        }
                    }
                    if (count($users)) {
                        foreach ($users as $user) {
                            $this->redis->delete(RedisName::Permission . $user['OrganizationId'] . '_' . $user['UserId']);
                        }
                    }
                    break;
                case Permission::VISIABLE_ADMIN:
                    $staff = Staff::findFirst(Staff::ADMINISTRATION);
                    if (!$staff) {
                        throw new LogicException('超级管理员数据丢失', Status::BadRequest);
                    }
                    $permissions = Permission::find(['conditions' => 'Visiable=?0', 'bind' => [Permission::VISIABLE_ADMIN]]);
                    if (count($permissions->toArray())) {
                        $permissionRole = RolePermission::find(['conditions' => 'RoleId=?0', 'bind' => [$staff->Role]]);
                        if (count($permissionRole->toArray())) {
                            $permissionRole->delete();
                        }
                        foreach ($permissions as $permission) {
                            $rolePermission = new RolePermission();
                            $rolePermission->RoleId = $staff->Role;
                            $rolePermission->PermissionId = $permission->Id;
                            if ($rolePermission->save() === false) {
                                $exception->loadFromModel($rolePermission);
                                throw $exception;
                            }
                        }
                        $this->redis->delete(RedisName::Staff . $staff->Id);
                    }
                    break;
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
     * 删除资源
     */
    public function deletePermissionAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isDelete()) {
                $id = $this->request->getPut('Id');
                $permission = Permission::findFirst(sprintf('Id=%d', $id));
                if (!$permission) {
                    throw $exception;
                }
                $rolePermission = RolePermission::find(['conditions' => 'PermissionId=?0', 'bind' => [$permission->Id]]);
                if ($rolePermission) {
                    $roleIds = array_unique(array_column($rolePermission->toArray(), 'RoleId'));
                    $users = OrganizationUser::query()->inWhere('Role', $roleIds)->execute();
                    if ($users) {
                        foreach ($users as $user) {
                            $this->redis->delete(RedisName::Permission . $user->OrganizationId . '_' . $user->UserId);
                        }
                    }
                    $rolePermission->delete();
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if ($permission->delete()) {
                $this->response->setJsonContent(['message' => '删除成功']);
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 权限列表
     */
    public function listAction()
    {
        $query = Permission::query();
        $visiable = $this->request->get('Visiable', 'int');
        if (isset($visiable) && is_numeric($visiable)) {
            $query->where(sprintf('Visiable=%d', $visiable));
        }
        $paginator = new QueryBuilder(
            [
                "builder" => $query->createBuilder(),
                "limit"   => $this->request->get('PageSize') ?: 10,
                "page"    => $this->request->get('Page') ?: 1,
            ]
        );
        $this->outputPagedJson($paginator);
    }

    public function readAction()
    {
        $permission = Permission::findFirst(sprintf('Id=%d', $this->request->get('Id', 'int')));
        if (!$permission) {
            throw new ParamException(Status::BadRequest);
        }
        $result = $permission->toArray();
        $result['Default'] = [];
        if (Permission::DEFAULT_HOSPITAL & $permission->Default) {
            $result['Default'][] = Permission::DEFAULT_HOSPITAL;
        }
        if (Permission::DEFAULT_SLAVE & $permission->Default) {
            $result['Default'][] = Permission::DEFAULT_SLAVE;
        }
        if (Permission::DEFAULT_SUPPLIER & $permission->Default) {
            $result['Default'][] = Permission::DEFAULT_SUPPLIER;
        }
        $this->response->setJsonContent($result);
    }
}