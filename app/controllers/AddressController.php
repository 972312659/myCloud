<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/11/5
 * Time: 下午2:27
 * 地址管理
 */

namespace App\Controllers;

use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\Address;
use App\Models\Location;


class AddressController extends Controller
{
    /**
     * 地址列表
     */
    public function addressListAction()
    {
        $result = $this->modelsManager->createBuilder()
            ->columns(['A.Id', 'A.Contacts', 'A.Phone', 'A.Default', 'A.ProvinceId', 'A.CityId', 'A.AreaId', 'A.Address', 'LP.Name as ProvinceName', 'LC.Name as CityName', 'LA.Name as AreaName'])
            ->addFrom(Address::class, 'A')
            ->leftJoin(Location::class, 'LP.Id=A.ProvinceId', 'LP')
            ->leftJoin(Location::class, 'LC.Id=A.CityId', 'LC')
            ->leftJoin(Location::class, 'LA.Id=A.AreaId', 'LA')
            ->where(sprintf('OrganizationId=%d', $this->user->OrganizationId))
            ->orderBy('A.Default desc')->getQuery()->execute();
        $this->response->setJsonContent($result);
    }

    /**
     * 添加地址
     */
    public function addAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $organization = $this->user->OrganizationId;
            if (!$organization) {
                throw new LogicException('未登录', Status::Unauthorized);
            }
            $data = $this->request->getPost();
            if (!isset($data['Default']) || empty($data['Default']) || !is_numeric($data['Default'])) {
                $data['Default'] = Address::DEFAULT_NO;
            }
            $data['OrganizationId'] = $organization;
            $oldAddress = Address::findFirst([
                'conditions' => 'OrganizationId=?0 and Default=?1',
                'bind'       => [$organization, Address::DEFAULT_YES],
            ]);
            if (!$oldAddress) {
                $data['Default'] = Address::DEFAULT_YES;
            } else {
                if ($data['Default'] == Address::DEFAULT_YES) {
                    $oldAddress->Default = Address::DEFAULT_NO;
                    if (!$oldAddress->save()) {
                        $exception->loadFromModel($oldAddress);
                        throw $exception;
                    }
                }
            }
            $address = new Address();
            if ($address->save($data) === false) {
                $exception->loadFromModel($address);
                throw $exception;
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
     * 修改地址
     */
    public function updateAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPUt()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPut();
            $address = Address::findFirst([
                'conditions' => 'Id=?0 and OrganizationId=?1',
                'bind'       => [$data['Id'], $this->user->OrganizationId],
            ]);
            if (!$address) {
                throw $exception;
            }
            $defaultAddress = Address::findFirst([
                'conditions' => 'OrganizationId=?0 and Default=?1',
                'bind'       => [$this->user->OrganizationId, Address::DEFAULT_YES],
            ]);
            if ($defaultAddress->Id === $address->Id) {
                unset($data['Default']);
            } else {
                if (!isset($data['Default']) || $data['Default'] != Address::DEFAULT_YES) {
                    $data['Default'] = Address::DEFAULT_NO;
                } else {
                    $defaultAddress->Default = Address::DEFAULT_NO;
                    if ($defaultAddress->save() === false) {
                        $exception->loadFromModel($defaultAddress);
                        throw $exception;
                    }
                }
            }
            unset($data['OrganizationId']);
            if ($address->save($data) === false) {
                $exception->loadFromModel($address);
                throw $exception;
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
     * 删除地址
     */
    public function delAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isDelete()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPut();
            $address = Address::findFirst([
                'conditions' => 'Id=?0 and OrganizationId=?1',
                'bind'       => [$data['Id'], $this->user->OrganizationId],
            ]);
            if (!$address) {
                throw $exception;
            }
            if ($address->Default == Address::DEFAULT_YES) {
                throw new LogicException('默认地址，不能删除', Status::BadRequest);
            }
            $address->delete();
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }
}