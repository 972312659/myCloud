<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/9/17
 * Time: 上午10:38
 * For: 商城
 */

namespace App\Controllers;


use App\Enums\MedicineUnits;
use App\Enums\Mongo;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\ExpressHundred;
use App\Libs\product\Comparing;
use App\Libs\product\Mapper;
use App\Libs\product\Validator;
use App\Libs\Sphinx;
use App\Libs\sphinx\TableName as SphinxTableName;
use App\Models\Attribute;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCategory;
use App\Models\ProductLog;
use App\Models\ProductPicture;
use App\Models\ProductProperty;
use App\Models\ProductPropertyValue;
use App\Models\ProductUnit;
use App\Models\ProductUnitPicture;
use App\Models\ProductUnitProductPropertyValue;
use App\Models\ProductUnitStatus;
use App\Models\Property;
use Phalcon\Paginator\Adapter\NativeArray;
use Phalcon\Paginator\Adapter\QueryBuilder;

class ProductController extends Controller
{
    /**
     * 上架列表
     */
    public function sellListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $phql = "select p.Id,p.Name,p.Audit,p.Created,p.Manufacturer,d.m Status from 
(
select a.Id,sum(c.Status) as m from Product a left join ProductUnit b on b.ProductId=a.Id left join ProductUnitStatus c on c.ProductUnitId=b.Id where a.OrganizationId={$this->user->OrganizationId} GROUP BY a.Id ";
        //上下架状态
        if (isset($data['Status']) && is_numeric($data['Status'])) {
            switch ($data['Status']) {
                case ProductUnitStatus::STATUS_OFF:
                    $phql .= 'HAVING m=0';
                    break;
                case ProductUnitStatus::STATUS_ON:
                    $phql .= 'HAVING m>0';
                    break;
            }
        }
        $phql .= ' ) d left join Product p on p.Id=d.Id  where 1=1';
        $bind = [];
        //商品名称
        if (isset($data['Name']) && !empty($data['Name'])) {
            $phql .= ' and p.Name=?';
            $bind[] = $data['Name'];
        }
        //厂家名称
        if (isset($data['Manufacturer']) && !empty($data['Manufacturer'])) {
            $phql .= ' and p.Manufacturer=?';
            $bind[] = $data['Manufacturer'];
        }
        //商品状态
        if (!empty($data['Audit']) && is_numeric($data['Audit'])) {
            $phql .= ' and p.Audit=?';
            $bind[] = $data['Audit'];
        }
        $phql .= ' order by p.Created desc';
        $paginator = new NativeArray([
            'data'  => $this->db->query($phql, $bind)->fetchAll(),
            'limit' => $pageSize,
            'page'  => $page,
        ]);
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items;
        foreach ($datas as &$data) {
            $data['AuditName'] = Product::AUDIT_NAME[$data['Audit']];
            $data['StatusName'] = $data['Status'] > 0 ? '上架' : '下架';
            $data['Status'] = $data['Status'] > 0 ? 1 : 0;
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 新增商品
     */
    public function addAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            $organizationId = $this->user->OrganizationId;
            if (!$organizationId) {
                throw new LogicException('请重新登录', Status::Unauthorized);
            }
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPost('Data');
            $data = json_decode($data, true);
            //验证数据
            Validator::product($data);
            $way = 0;
            foreach ($data['Way'] as $datum) {
                $way = $way | $datum;
            }
            //默认
            if (count($data['Sku']) == 1) {
                $data['Sku'][0]['IsDefault'] = ProductUnit::IS_DEFAULT_YES;
            }
            //新增商品
            $product = new Product();
            $product->OrganizationId = $organizationId;
            $product->Name = $data['Name'];
            if (isset($data['Description']) && !empty($data['Description'])) {
                $product->Description = $data['Description'];
            }
            $product->Manufacturer = $data['Manufacturer'];
            $product->Way = $way;
            $product->ProductCategoryId = $data['ProductCategoryId'];
            if ($product->save() === false) {
                $exception->loadFromModel($product);
                throw $exception;
            }
            $product->refresh();
            //商品图片
            foreach ($data['Image'] as $key => $image) {
                $productPicture = new ProductPicture();
                $productPicture->ProductId = $product->Id;
                $productPicture->Image = $image['Value'];
                if ($productPicture->save() === false) {
                    $exception->loadFromModel($productPicture);
                    throw $exception;
                }
            }
            //关联商品描述属性并赋值
            foreach ($data['Attributes'] as $attribute) {
                $productAttribute = new ProductAttribute();
                $productAttribute->ProductId = $product->Id;
                $productAttribute->AttributeId = $attribute['Id'];
                $productAttribute->Value = $attribute['Value'];
                if ($productAttribute->save() === false) {
                    $exception->loadFromModel($productAttribute);
                    throw $exception;
                }
            }
            $temp = [];
            //关联商品销售属性
            foreach ($data['Properties'] as $datum) {
                if (!is_array($datum['PropertyValues']) || empty($datum['PropertyValues'])) {
                    throw new LogicException('商品销售属性格式错误', Status::BadRequest);
                }
                $productProperty = new ProductProperty();
                $productProperty->ProductId = $product->Id;
                $productProperty->PropertyId = $datum['Id'];
                if ($productProperty->save() === false) {
                    $exception->loadFromModel($productProperty);
                    throw $exception;
                }
                $productProperty->refresh();
                //商品销售属性赋值
                foreach ($datum['PropertyValues'] as $value) {
                    if (!empty(trim($value['Value']))) {
                        $productPropertyValue = new ProductPropertyValue();
                        $productPropertyValue->ProductId = $productProperty->ProductId;
                        $productPropertyValue->PropertyId = $productProperty->PropertyId;
                        $productPropertyValue->Value = $value['Value'];
                        if ($productPropertyValue->save() === false) {
                            $exception->loadFromModel($productPropertyValue);
                            throw $exception;
                        }
                        $productPropertyValue->refresh();
                        $temp[$productProperty->PropertyId][$value['Id']] = $productPropertyValue->Id;
                    }
                }
            }
            //创建每一个小单元商品
            foreach ($data['Sku'] as $sku) {
                $productUnit = new ProductUnit();
                $productUnit->ProductId = $product->Id;
                $productUnit->Price = $sku['Price'];
                $productUnit->PriceForSlave = $sku['PriceForSlave'];
                $productUnit->Postage = $sku['Postage'];
                $productUnit->IsDefault = $sku['IsDefault'];
                if ($productUnit->save() === false) {
                    $exception->loadFromModel($productUnit);
                    throw $exception;
                }
                $productUnit->refresh();
                $productUnitStatus = new ProductUnitStatus();
                $productUnitStatus->ProductUnitId = $productUnit->Id;
                $productUnitStatus->Stock = $sku['Stock'];
                $productUnitStatus->WarningLine = $sku['WarningLine'];
                if ($productUnitStatus->save() === false) {
                    $exception->loadFromModel($productUnitStatus);
                    throw $exception;
                }
                //关联每一个小单元商品和元素
                foreach ($sku['PropertyIds'] as $propertyId) {
                    if (!empty(trim($propertyId['Name']))) {
                        $productUnitProductPropertyValue = new ProductUnitProductPropertyValue();
                        $productUnitProductPropertyValue->ProductUnitId = $productUnit->Id;
                        $productUnitProductPropertyValue->ProductPropertyValueId = $temp[$propertyId['PropertyId']][$propertyId['PropertyValueId']];
                        if ($productUnitProductPropertyValue->save() === false) {
                            $exception->loadFromModel($productUnitProductPropertyValue);
                            throw $exception;
                        }
                    }
                }
                foreach ($sku['Images'] as $image) {
                    $productUnitPicture = new ProductUnitPicture();
                    $productUnitPicture->ProductUnitId = $productUnit->Id;
                    $productUnitPicture->Image = $image['Value'];
                    if ($productUnitPicture->save() === false) {
                        $exception->loadFromModel($productUnitPicture);
                        throw $exception;
                    }
                }
            }
            //记录操作
            ProductLog::log($product->Id, $product->Name, ProductLog::STATUS_NONE, ProductLog::STATUS_AUDITING, $this->user->Id, $this->user->Name);
            $this->db->commit();
            //写入sphinx
            $productSphinx = new \App\Libs\sphinx\model\Product(new Sphinx($this->getDI()->getShared('sphinx'), SphinxTableName::Product));
            if (!$productSphinx->save($product, '')) {
                throw new LogicException('sphinx缓存错误', Status::BadRequest);
            }
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 卖方读取一条product
     */
    public function readSellProductAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            /**
             * @var Product $product
             */
            $product = Product::findFirst([
                'conditions' => 'Id=?0 and OrganizationId=?1',
                'bind'       => [$this->request->get('Id'), $this->user->OrganizationId],
            ]);
            if (!$product) {
                throw $exception;
            }
            //Product
            $result = $product->toArray();
            $result['ProductCategoryName'] = $product->ProductCategory->Name;
            $way = [];
            foreach (Product::WAY_SHOW as $value) {
                if ($value['Id'] & $result['Way']) {
                    $way[] = $value['Id'];
                }
            }
            //Way
            $result['Way'] = $way;
            //Image
            $result['Image'] = ProductPicture::query()
                ->columns(['Id', 'Image as Value'])
                ->where('ProductId=:ProductId:')
                ->bind(['ProductId' => $product->Id])
                ->execute()->toArray();
            //Properties
            $result['Properties'] = [];
            $properties = Property::query()
                ->columns(['Id', 'Name', 'MaxLength'])
                ->join(ProductProperty::class, 'P.PropertyId=Id', 'P')
                ->where(sprintf('P.ProductId=%d', $product->Id))
                ->execute();
            $productPropertyValues = ProductPropertyValue::find([
                'conditions' => 'ProductId=?0',
                'bind'       => [$product->Id],
            ]);
            $productPropertyValues_new = [];
            $productPropertyValues_forSku = [];
            $properties_forSku = [];
            foreach ($productPropertyValues as $value) {
                $productPropertyValues_new[$value->PropertyId][] = ['Id' => $value->Id, 'Value' => $value->Value];
                $productPropertyValues_forSku[$value->Id] = ['PropertyId' => $value->PropertyId, 'Value' => $value->Value];
            }
            foreach ($properties as $property) {
                $result['Properties'][] = ['Id' => $property->Id, 'Value' => $property->Name, 'MaxLength' => $property->MaxLength, 'PropertyValues' => $productPropertyValues_new[$property->Id]];
                $properties_forSku[$property->Id] = $property->Name;
            }
            //Sku
            $result['Sku'] = [];
            $productUnits = ProductUnit::find([
                'conditions' => 'ProductId=?0',
                'bind'       => [$product->Id],
            ])->toArray();
            $productUnitProductPropertyValues = ProductUnitProductPropertyValue::query()
                ->inWhere('ProductUnitId', array_column($productUnits, 'Id'))
                ->execute();
            $productUnitProductPropertyValues_new = [];
            foreach ($productUnitProductPropertyValues as $value) {
                $productUnitProductPropertyValues_new[$value->ProductUnitId][] = [
                    'PropertyId'        => $productPropertyValues_forSku[$value->ProductPropertyValueId]['PropertyId'],
                    'PropertyName'      => $properties_forSku[$productPropertyValues_forSku[$value->ProductPropertyValueId]['PropertyId']],
                    'PropertyValueId'   => $value->ProductPropertyValueId,
                    'PropertyValueName' => $productPropertyValues_forSku[$value->ProductPropertyValueId]['Value'],
                ];
            }
            foreach ($properties_forSku as $id => $item) {
                foreach ($productUnitProductPropertyValues_new as &$productUnitProductPropertyValue) {
                    if (!in_array($id, array_column($productUnitProductPropertyValue, 'PropertyId'))) {
                        $productUnitProductPropertyValue[] = [
                            'PropertyId'        => $id,
                            'PropertyName'      => $item,
                            'PropertyValueId'   => '',
                            'PropertyValueName' => '',
                        ];
                    }
                }
            }
            $skuImages = ProductUnitPicture::query()
                ->inWhere('ProductUnitId', array_column($productUnits, 'Id'))
                ->execute();
            $skuImages_new = [];
            foreach ($skuImages as $image) {
                $skuImages_new[$image->ProductUnitId][] = ['Id' => $image->Id, 'Value' => $image->Image];
            }
            $skuStatus = ProductUnitStatus::query()
                ->inWhere('ProductUnitId', array_column($productUnits, 'Id'))
                ->execute();
            $skuStatus_new = [];
            foreach ($skuStatus as $status) {
                $skuStatus_new[$status->ProductUnitId] = ['Stock' => $status->Stock, 'WarningLine' => $status->WarningLine, 'Status' => $status->Status,];
            }
            foreach ($productUnits as $unit) {
                $result['Sku'][] = [
                    'Id'            => $unit['Id'],
                    'Price'         => $unit['Price'],
                    'PriceForSlave' => $unit['PriceForSlave'],
                    'Postage'       => $unit['Postage'],
                    'IsDefault'     => $unit['IsDefault'],
                    'Stock'         => $skuStatus_new[$unit['Id']]['Stock'],
                    'WarningLine'   => $skuStatus_new[$unit['Id']]['WarningLine'],
                    'Status'        => $skuStatus_new[$unit['Id']]['Status'],
                    'Number'        => $unit['Number'],
                    'PropertyIds'   => $productUnitProductPropertyValues_new[$unit['Id']] ?: [],
                    'Images'        => $skuImages_new[$unit['Id']],
                ];
            }
            //Attributes
            $result['Attributes'] = ProductAttribute::query()
                ->columns(['AttributeId as Id', 'Value'])
                ->leftJoin(Attribute::class, 'A.Id=AttributeId', 'A')
                ->where(sprintf('ProductId=%d', $product->Id))
                ->execute()->toArray();
            $this->response->setJsonContent($result);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 修改商品
     */
    public function updateAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            $organizationId = $this->user->OrganizationId;
            if (!$organizationId) {
                throw new LogicException('请重新登录', Status::Unauthorized);
            }
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $json = $this->request->getPut('Data');
            $data = json_decode($json, true);
            $way = $data['Way'];
            $way_tmp = 0;
            foreach ($data['Way'] as $datum) {
                $way_tmp = $way_tmp | $datum;
            }
            $data['Way'] = $way_tmp;
            $json = json_encode($data);
            $data['Way'] = $way;
            /**
             * @var Product $product
             */
            $product = Product::findFirst(sprintf('Id=%d', $data['Id']));
            if (!$product) {
                throw $exception;
            }
            if ($product->Audit === Product::AUDIT_WAIT) {
                throw new LogicException('审核中，不能重复提交修改', Status::BadRequest);
            }
            $mapper = new Mapper($this->getDI()->getShared(Mongo::database));
            $newProduct = $mapper->jsonToProduct($json);
            $oldProduct = $mapper->mongoToProduct((int)$product->Id);
            $comparing = new Comparing();
            if (($oldProduct == null && $comparing->diff(Mapper::product($product->Id), $newProduct)) || $comparing->diff($oldProduct, $newProduct)) {
                Validator::product($data);
                $product->Name = $data['Name'];
                if (isset($data['Description']) && !empty($data['Description'])) {
                    $product->Description = $data['Description'];
                }
                $product->Manufacturer = $data['Manufacturer'];
                $product->Way = $way_tmp;
                $product->ProductCategoryId = $data['ProductCategoryId'];
                $product->Audit = Product::AUDIT_WAIT;
                $product->Updated = time();
                if ($product->save() === false) {
                    $exception->loadFromModel($product);
                    throw $exception;
                }
                $product->refresh();
                //商品图片
                foreach ($data['Image'] as $key => $image) {
                    /**
                     * @var ProductPicture $productPicture
                     */
                    if (isset($image['Id']) && is_numeric($image['Id'])) {
                        $productPicture = ProductPicture::findFirst(sprintf('Id=%d', $image['Id']));
                    } else {
                        $productPicture = false;
                    }
                    if (!$productPicture) {
                        $productPicture = new ProductPicture();
                        $productPicture->ProductId = $product->Id;
                    }
                    $productPicture->Image = $image['Value'];
                    if ($productPicture->save() === false) {
                        $exception->loadFromModel($productPicture);
                        throw $exception;
                    }
                    //删除多余的
                    $pictures = ProductPicture::find([
                        'conditions' => 'ProductId=?0 and Id!=?1',
                        'bind'       => [$product->Id, $productPicture->Id],
                    ]);
                    if (count($pictures->toArray())) {
                        $pictures->delete();
                    }
                }
                //关联商品描述属性并赋值
                foreach ($data['Attributes'] as $attribute) {
                    if (isset($attribute['Id']) && is_numeric($attribute['Id'])) {
                        $productAttribute = ProductAttribute::findFirst([
                            'conditions' => 'ProductId=?0 and AttributeId=?1',
                            'bind'       => [$product->Id, $attribute['Id']],
                        ]);
                    } else {
                        $productAttribute = false;
                    }
                    if (!$productAttribute) {
                        $productAttribute = new ProductAttribute();
                        $productAttribute->ProductId = $product->Id;
                    }
                    $productAttribute->AttributeId = $attribute['Id'];
                    $productAttribute->Value = $attribute['Value'];
                    if ($productAttribute->save() === false) {
                        $exception->loadFromModel($productAttribute);
                        throw $exception;
                    }
                }
                $temp = [];
                $notDelProperties = [];
                $notDelPropertyValues = [];
                //关联商品销售属性
                foreach ($data['Properties'] as $datum) {
                    if (!is_array($datum['PropertyValues']) || empty($datum['PropertyValues'])) {
                        throw new LogicException('商品销售属性格式错误', Status::BadRequest);
                    }
                    /**
                     * @var ProductProperty $productProperty
                     */
                    if (isset($datum['Id']) && is_numeric($datum['Id'])) {
                        $productProperty = ProductProperty::findFirst([
                            'conditions' => 'ProductId=?0 and PropertyId=?1',
                            'bind'       => [$product->Id, $datum['Id']],
                        ]);
                    } else {
                        $productProperty = false;
                    }
                    if (!$productProperty) {
                        $productProperty = new ProductProperty();
                        $productProperty->ProductId = $product->Id;
                        $productProperty->PropertyId = $datum['Id'];
                    }
                    if ($productProperty->save() === false) {
                        $exception->loadFromModel($productProperty);
                        throw $exception;
                    }
                    $productProperty->refresh();
                    $notDelProperties[] = $productProperty->PropertyId;
                    //商品销售属性赋值
                    foreach ($datum['PropertyValues'] as $value) {
                        if (!empty(trim($value['Value']))) {
                            if (isset($value['Id']) && is_numeric($value['Id'])) {
                                $productPropertyValue = ProductPropertyValue::findFirst(sprintf('Id=%d', $value['Id']));
                            } else {
                                $productPropertyValue = false;
                            }
                            if (!$productPropertyValue) {
                                $productPropertyValue = new ProductPropertyValue();
                                $productPropertyValue->ProductId = $productProperty->ProductId;
                                $productPropertyValue->PropertyId = $productProperty->PropertyId;
                            }
                            $productPropertyValue->Value = $value['Value'];
                            if ($productPropertyValue->save() === false) {
                                $exception->loadFromModel($productPropertyValue);
                                throw $exception;
                            }
                            $productPropertyValue->refresh();
                            $notDelPropertyValues[] = $productPropertyValue->Id;
                            $temp[$productProperty->PropertyId][$value['Id']] = $productPropertyValue->Id;
                        }
                    }
                }
                //删除多余属性
                /**
                 * @var ProductProperty $needDelProperties
                 */
                $needDelProperties = ProductProperty::query()
                    ->where(sprintf('ProductId=%d', $product->Id))
                    ->notInWhere('PropertyId', $notDelProperties)
                    ->execute();
                /**
                 * @var ProductPropertyValue $needDelPropertyValues
                 */
                $needDelPropertyValues = ProductPropertyValue::query()
                    ->where(sprintf('ProductId=%d', $product->Id))
                    ->notInWhere('Id', $notDelPropertyValues)
                    ->execute();
                if (count($needDelProperties->toArray())) {
                    $needDelProperties->delete();
                }
                if (count($needDelPropertyValues->toArray())) {
                    $needDelPropertyValues->delete();
                }
                //创建每一个小单元商品
                foreach ($data['Sku'] as $sku) {
                    if (isset($sku['Id']) && is_numeric($sku['Id'])) {
                        $productUnit = ProductUnit::findFirst(sprintf('Id=%d', $sku['Id']));
                    } else {
                        $productUnit = false;
                    }
                    if (!$productUnit) {
                        $productUnit = new ProductUnit();
                        $productUnit->ProductId = $product->Id;
                    }
                    $productUnit->Price = $sku['Price'];
                    $productUnit->PriceForSlave = $sku['PriceForSlave'];
                    $productUnit->Postage = $sku['Postage'];
                    $productUnit->IsDefault = $sku['IsDefault'];
                    if ($productUnit->save() === false) {
                        $exception->loadFromModel($productUnit);
                        throw $exception;
                    }
                    $productUnit->refresh();
                    $productUnitStatus = ProductUnitStatus::findFirst(sprintf('ProductUnitId=%d', $productUnit->Id));
                    if (!$productUnitStatus) {
                        $productUnitStatus = new ProductUnitStatus();
                        $productUnitStatus->ProductUnitId = $productUnit->Id;
                    }
                    $productUnitStatus->Stock = $sku['Stock'];
                    $productUnitStatus->WarningLine = $sku['WarningLine'];
                    if ($productUnitStatus->save() === false) {
                        $exception->loadFromModel($productUnitStatus);
                        throw $exception;
                    }
                    //关联每一个小单元商品和元素
                    foreach ($sku['PropertyIds'] as $propertyId) {
                        if (!empty(trim($propertyId['PropertyValueName']))) {
                            if (isset($propertyId['PropertyValueId']) && is_numeric($propertyId['PropertyValueId'])) {
                                $productUnitProductPropertyValue = ProductUnitProductPropertyValue::findFirst([
                                    'conditions' => 'ProductUnitId=?0 and ProductPropertyValueId=?1',
                                    'bind'       => [$productUnit->Id, $propertyId['PropertyValueId']],
                                ]);
                            } else {
                                $productUnitProductPropertyValue = false;
                            }
                            if (!$productUnitProductPropertyValue) {
                                $productUnitProductPropertyValue = new ProductUnitProductPropertyValue();
                                $productUnitProductPropertyValue->ProductUnitId = $productUnit->Id;
                                $productUnitProductPropertyValue->ProductPropertyValueId = $temp[$propertyId['PropertyId']][$propertyId['PropertyValueId']];
                                if ($productUnitProductPropertyValue->save() === false) {
                                    $exception->loadFromModel($productUnitProductPropertyValue);
                                    throw $exception;
                                }
                            }
                            $propertyId = $productUnitProductPropertyValue->ProductPropertyValue->PropertyId;
                            $productPropertyValueIds = ProductPropertyValue::find([
                                'conditions' => 'ProductId=?0 and PropertyId=?1',
                                'bind'       => [$product->Id, $propertyId],
                            ])->toArray();
                            if (count($productPropertyValueIds) > 1) {
                                $productUnitProductPropertyValue_old = ProductUnitProductPropertyValue::query()
                                    ->inWhere('ProductPropertyValueId', array_column($productPropertyValueIds, 'Id'))
                                    ->andWhere(sprintf('ProductPropertyValueId!=%d', $productUnitProductPropertyValue->ProductPropertyValueId))
                                    ->andWhere(sprintf('ProductUnitId=%d', $productUnit->Id))
                                    ->execute();
                                if (count($productUnitProductPropertyValue_old->toArray())) {
                                    $productUnitProductPropertyValue_old->delete();
                                }
                            }
                        }
                    }
                    foreach ($sku['Images'] as $image) {
                        if (isset($image['Id']) && is_numeric($image['Id'])) {
                            $productUnitPicture = ProductUnitPicture::findFirst(sprintf('Id=%d', $image['Id']));
                        } else {
                            $productUnitPicture = false;
                        }
                        if (!$productUnitPicture) {
                            $productUnitPicture = new ProductUnitPicture();
                            $productUnitPicture->ProductUnitId = $productUnit->Id;
                        }
                        $productUnitPicture->Image = $image['Value'];
                        if ($productUnitPicture->save() === false) {
                            $exception->loadFromModel($productUnitPicture);
                            throw $exception;
                        }
                    }
                }
                //记录操作
                ProductLog::log($product->Id, $product->Name, $product->Audit === Product::AUDIT_ON ? ProductLog::STATUS_AUDITED : ProductLog::STATUS_AUDITED_RECALL, ProductLog::STATUS_AUDITING, $this->user->Id, $this->user->Name);
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
     * 商品撤回
     */
    public function recallAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            /**
             * @var Product $product
             */
            $product = Product::findFirst([
                'conditions' => 'OrganizationId=?0 and Id=?1',
                'bind'       => [$this->user->OrganizationId, $this->request->getPut('Id')],
            ]);
            if (!$product) {
                throw $exception;
            }
            if ($product->Audit !== Product::AUDIT_WAIT) {
                throw new LogicException('只能在待审核状态撤回', Status::BadRequest);
            }
            $product->Audit = Product::AUDIT_RECALL;
            if (!$product->save()) {
                $exception->loadFromModel($product);
                throw $exception;
            }
            //记录操作
            ProductLog::log($product->Id, $product->Name, ProductLog::STATUS_AUDITING, ProductLog::STATUS_AUDITED_RECALL, $this->user->Id, $this->user->Name);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * sku上下架
     */
    public function skuStatusAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $sphinx_status = ProductUnitStatus::STATUS_OFF;
            $productId = $this->request->getPut('ProductId');
            $skuId = $this->request->getPut('SkuId');
            /**
             * @var ProductUnit $sku
             */
            $sku = ProductUnit::findFirst(sprintf('Id=%d', $skuId));
            /**
             * @var Product $product
             */
            $product = Product::findFirst([
                'conditions' => 'OrganizationId=?0 and Id=?1',
                'bind'       => [$this->user->OrganizationId, $productId ?: $sku->ProductId],
            ]);
            /**
             * @var ProductUnitStatus $skuStatus
             */
            if (!$product) {
                throw $exception;
            }
            $mapper = new Mapper($this->getDI()->getShared(Mongo::database));
            if (!$mapper->getMongoId($product->Id)) {
                throw new LogicException('等待第一次审核通过后才能操作', Status::BadRequest);
            }
            $skuIds = ProductUnit::find([
                'conditions' => 'ProductId=?0',
                'bind'       => [$product->Id],
            ])->toArray();
            $skuStatues = ProductUnitStatus::query()
                ->inWhere('ProductUnitId', array_column($skuIds, 'Id'))
                ->execute();
            $totalOn = array_sum(array_column($skuStatues->toArray(), 'Status'));
            if ($productId) {
                //总开关
                if ($totalOn > 0) {
                    $status = ProductUnitStatus::STATUS_OFF;
                } else {
                    $status = ProductUnitStatus::STATUS_ON;
                    $sphinx_status = ProductUnitStatus::STATUS_ON;
                }
                foreach ($skuStatues as $skuStatus) {
                    $skuStatus->Status = $status;
                    if (!$skuStatus->save()) {
                        $exception->loadFromModel($skuStatus);
                        throw $exception;
                    }
                }
                //记录操作
                ProductLog::log($product->Id, $product->Name, $totalOn > 0 ? ProductLog::STATUS_ON : ProductLog::STATUS_OFF, $totalOn > 0 ? ProductLog::STATUS_OFF : ProductLog::STATUS_ON, $this->user->Id, $this->user->Name, true);
            } elseif ($skuId) {
                //单开关
                if (!$sku) {
                    throw $exception;
                }
                $skuStatus = ProductUnitStatus::findFirst(sprintf('ProductUnitId=%d', $sku->Id));
                if (!$skuStatus) {
                    throw $exception;
                }
                if ($totalOn > 1) {
                    if ($sku->IsDefault === ProductUnit::IS_DEFAULT_YES && $skuStatus->Status === ProductUnitStatus::STATUS_ON) {
                        throw new LogicException('下架其他非默认商品之后，才能下架默认商品', Status::BadRequest);
                    }
                    $sphinx_status = ProductUnitStatus::STATUS_ON;
                } elseif ($totalOn == 0) {
                    if ($sku->IsDefault === ProductUnit::IS_DEFAULT_NO && $skuStatus->Status === ProductUnitStatus::STATUS_OFF) {
                        throw new LogicException('先上架默认商品后，才能上架其他商品', Status::BadRequest);
                    }
                    $sphinx_status = ProductUnitStatus::STATUS_ON;
                } else {
                    if ($skuStatus->Status === ProductUnitStatus::STATUS_OFF) $sphinx_status = ProductUnitStatus::STATUS_ON;
                }
                $skuStatus->Status = $skuStatus->Status === ProductUnitStatus::STATUS_ON ? ProductUnitStatus::STATUS_OFF : ProductUnitStatus::STATUS_ON;
                if (!$skuStatus->save()) {
                    $exception->loadFromModel($skuStatus);
                    throw $exception;
                }
                $skuStatus->refresh();
                //记录操作
                if ($totalOn == 1) {
                    ProductLog::log($product->Id, $product->Name, ProductLog::STATUS_ON, ProductLog::STATUS_OFF, $this->user->Id, $this->user->Name, true);
                } else {
                    ProductLog::log($product->Id, $product->Name, $skuStatus->Status === ProductUnitStatus::STATUS_ON ? ProductLog::STATUS_OFF : ProductLog::STATUS_ON, $skuStatus->Status === ProductUnitStatus::STATUS_ON ? ProductLog::STATUS_ON : ProductLog::STATUS_OFF, $this->user->Id, $this->user->Name);
                }
            }
            //更新sphinx
            $sphinxProduct = new \App\Libs\sphinx\model\Product(new Sphinx($this->sphinx, SphinxTableName::Product));
            if (!$sphinxProduct->update($product->Id, ['status' => $sphinx_status])) {
                throw new LogicException('缓存错误', Status::BadRequest);
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
     * 删除sku
     */
    public function delSkuAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isDelete()) {
                $this->db->begin();
                $productUnit = ProductUnit::findFirst(sprintf('Id=%d', $this->request->getPut('Id')));
                if (!$productUnit) {
                    throw $exception;
                }
                /**
                 * @var Product $product
                 */
                $product = Product::findFirst(sprintf('Id=%d', $productUnit->ProductId));
                if (!$product || ((int)$product->OrganizationId !== (int)$this->user->OrganizationId)) {
                    throw $exception;
                }
                if ($productUnit->IsDefault == ProductUnit::IS_DEFAULT_YES) {
                    throw new LogicException('此项为默认显示，不能删除', Status::BadRequest);
                }
                $productUnitProductPropertyValue = ProductUnitProductPropertyValue::find([
                    'conditions' => 'ProductUnitId=?0',
                    'bind'       => [$productUnit->Id],
                ]);
                $productUnitPicture = ProductUnitPicture::find([
                    'conditions' => 'ProductUnitId=?0',
                    'bind'       => [$productUnit->Id],
                ]);
                $productUnitStatus = ProductUnitStatus::findFirst(sprintf('ProductUnitId=%d', $productUnit->Id));
                if ($productUnitStatus) {
                    $productUnitStatus->delete();
                }
                if (count($productUnitPicture->toArray())) $productUnitPicture->delete();
                if (count($productUnitProductPropertyValue->toArray())) $productUnitProductPropertyValue->delete();
                $productUnit->delete();
                //产生新的mongo版本
                $mapper = new Mapper($this->getDI()->getShared(Mongo::database));
                if ($mapper->getMongoId($product->Id)) {
                    $mongoId = $mapper->createMongo($product->Id);
                    $sphinxProduct = new \App\Libs\sphinx\model\Product(new Sphinx($this->sphinx, SphinxTableName::Product));
                    if (!$sphinxProduct->update($product->Id, ['mongoid' => $mongoId])) {
                        throw new LogicException('缓存错误', Status::BadRequest);
                    }
                }
                switch ($product->Audit) {
                    case Product::AUDIT_ON:
                        $beforeStatus = ProductLog::STATUS_AUDITED;
                        break;
                    case Product::AUDIT_RECALL:
                        $beforeStatus = ProductLog::STATUS_AUDITED_RECALL;
                        break;
                    default:
                        $beforeStatus = ProductLog::STATUS_AUDITING;
                }
                //记录操作
                ProductLog::log($product->Id, $product->Name, $beforeStatus, ProductLog::STATUS_AUDITED_RECALL, $this->user->Id, $this->user->Name);
                $this->db->commit();
            }
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 删除商品销售属性(Unused)
     */
    public function delPropertyAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isDelete()) {
                $productPropertyValue = ProductPropertyValue::findFirst(sprintf('Id=%d', $this->request->getPut('Id')));
                if (!$productPropertyValue) {
                    throw $exception;
                }
                $product = Product::findFirst(sprintf('Id=%d', $productPropertyValue->ProductId));
                if (!$product || $product->OrganizationId !== $this->user->OrganizationId) {
                    throw $exception;
                }
                $productUnits = ProductUnit::find([
                    'conditions' => 'ProductId=?0',
                    'bind'       => [$product->Id],
                ])->toArray();
                $productUnitProductPropertyValues = ProductUnitProductPropertyValue::query()
                    ->inWhere('ProductUnitId', array_column($productUnits, 'Id'))
                    ->execute()->toArray();
                if (in_array($productPropertyValue->Id, array_column($productUnitProductPropertyValues, 'ProductPropertyValueId'))) {
                    throw new LogicException('商品sku使用该属性，不能删除', Status::BadRequest);
                }
                $productPropertyValue->delete();
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 删除商品
     */
    public function delProductAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isDelete()) {
                $this->db->begin();
                $productId = $this->request->getPut('Id', 'int');
                $product = Product::findFirst([
                    'conditions' => 'Id=?0 and OrganizationId=?1',
                    'bind'       => [$productId, $this->user->OrganizationId],
                ]);
                $productName = $product->Name;
                $audit = $product->Audit;
                if (!$product) {
                    throw $exception;
                }
                $productPicture = ProductPicture::find([
                    'conditions' => 'ProductId=?0',
                    'bind'       => [$product->Id],
                ]);
                $productAttribute = ProductAttribute::find([
                    'conditions' => 'ProductId=?0',
                    'bind'       => [$product->Id],
                ]);
                $productProperty = ProductProperty::find([
                    'conditions' => 'ProductId=?0',
                    'bind'       => [$product->Id],
                ]);
                $productPropertyValue = ProductPropertyValue::find([
                    'conditions' => 'ProductId=?0',
                    'bind'       => [$product->Id],
                ]);
                $productUnit = ProductUnit::find([
                    'conditions' => 'ProductId=?0',
                    'bind'       => [$product->Id],
                ]);
                $productUnitProductPropertyValue = ProductUnitProductPropertyValue::query()
                    ->inWhere('ProductUnitId', array_column($productUnit->toArray(), 'Id'))
                    ->execute();
                //删除
                if (count($productUnitProductPropertyValue->toArray())) $productUnitProductPropertyValue->delete();
                if (count($productUnit->toArray())) $productUnit->delete();
                if (count($productPropertyValue->toArray())) $productPropertyValue->delete();
                if (count($productProperty->toArray())) $productProperty->delete();
                if (count($productAttribute->toArray())) $productAttribute->delete();
                if (count($productPicture->toArray())) $productPicture->delete();
                $product->delete();
                //删除sphinx
                $sphinxProduct = new \App\Libs\sphinx\model\Product(new Sphinx($this->sphinx, SphinxTableName::Product));
                if (!$sphinxProduct->delete($productId)) {
                    throw new LogicException('缓存错误', Status::BadRequest);
                }
                switch ($audit) {
                    case Product::AUDIT_ON:
                        $beforeStatus = ProductLog::STATUS_AUDITED;
                        break;
                    case Product::AUDIT_RECALL:
                        $beforeStatus = ProductLog::STATUS_AUDITED_RECALL;
                        break;
                    default:
                        $beforeStatus = ProductLog::STATUS_AUDITING;
                }
                //记录操作
                ProductLog::log($productId, $productName, $beforeStatus, ProductLog::STATUS_AUDITED_RECALL, $this->user->Id, $this->user->Name, true);
                $this->db->commit();
            }
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 销售属性
     */
    public function propertiesAction()
    {
        $properties = Property::find();
        $this->response->setJsonContent($properties);
    }

    /**
     * 描述属性
     */
    public function attributesAction()
    {
        $attributes = Attribute::find([
            'conditions' => 'ProductCategoryId=?0',
            'bind'       => [ProductCategory::DRUG],
            'order'      => 'IsRequired desc',
        ]);
        $this->response->setJsonContent($attributes);
    }

    /**
     * 商品分类
     */
    public function categoryAction()
    {
        $categories = ProductCategory::find([
            'conditions' => 'Pid=?0',
            'bind'       => [$this->request->get('Pid') ?: ProductCategory::TOP_PID],
        ]);
        $this->response->setJsonContent($categories);
    }

    /**
     * 发布对象
     */
    public function releaseWayAction()
    {
        $this->response->setJsonContent(Product::WAY_SHOW);
    }

    /**
     * 供货订单列表
     */
    public function orderSellListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = Order::query()
            ->columns(['Id', 'OrderNumber', 'Created', 'Status', 'Amounts'])
            ->where(sprintf('SellerOrganizationId=%d', $this->user->OrganizationId));
        $bind = [];
        //订单编号
        if (isset($data['OrderNumber']) && !empty($data['OrderNumber'])) {
            $query->andWhere('OrderNumber=:OrderNumber:');
            $bind['OrderNumber'] = $data['OrderNumber'];
        }
        //订单状态
        if (!empty($data['Status']) && is_numeric($data['Status'])) {
            $query->andWhere('Status=:Status:');
            $bind['Status'] = $data['Status'];
        }
        //时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("Created>=:StartTime:");
            $bind['StartTime'] = $data['StartTime'];
        }
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                return $this->response->setStatusCode(Status::BadRequest);
            }
            $query->andWhere("Created<=:EndTime:");
            $bind['EndTime'] = $data['EndTime'];
        }
        $query->bind($bind);
        $query->orderBy('Created desc');
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
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
        return $this->response->setJsonContent($result);
    }

    /**
     * 编辑订单
     * 1：修改订单金额
     * 2：修改物流信息
     */
    public function editOrderAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $organizationId = $this->user->OrganizationId;
            $data = $this->request->getPut();
            $order = Order::findFirst(sprintf('Id=%d', $data['Id']));
            if (!$order || !in_array($organizationId, [$order->BuyerOrganizationId, $order->SellerOrganizationId])) {
                throw $exception;
            }
            $whiteList = [];
            switch ($organizationId) {
                case $order->BuyerOrganizationId:
                    // $whiteList = ['Postcode', 'ProvinceId', 'CityId', 'AreaId', 'Address', 'Com', 'Nu'];
                    break;
                case $order->SellerOrganizationId;
                    if ($order->Status === Order::STATUS_WAIT_PAY) {
                        $whiteList = ['Amounts'];
                    } elseif ($order->Status === Order::STATUS_WAIT_SEND) {
                        $whiteList = ['Com', 'Nu'];
                    }
                    break;
            }
            if ($order->save($data, $whiteList) === false) {
                $exception->loadFromModel($order);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }


    /**
     * 销售统计
     */
    public function salesStatisticsAction()
    {

    }

    /**
     * 药品的单位列表
     */
    public function unitsAction()
    {
        $units = MedicineUnits::map();
    }

    /**
     * 物流查询
     */
    public function expressInfoAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $order = Order::findFirst(sprintf('Id=%d', $this->request->get('Id')));
            if (!$order) {
                throw $exception;
            }
            if (!in_array($this->user->OrganizationId, [$order->BuyerOrganizationId, $order->SellerOrganizationId])) {
                throw $exception;
            }
            $express = new ExpressHundred();
            $result = $express->get($order->Com, $order->Nu);
            if (!$result['status']) {
                throw new LogicException($result['message'], Status::BadRequest);
            }
            $this->response->setJsonContent($result['message']);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 买家读取商品
     * 情况：
     * 1、商品列表到详情
     * 2、购物车到详情
     * 3、订单到详情
     */
    public function readBuyProductAction()
    {
        $productId = (int)$this->request->get('Id', 'int');
        $mongoId = $this->request->get('VersionId');
        $auth = $this->session->get('auth');
        if (!$auth) {
            throw new LogicException('请登录', Status::Unauthorized);
        }
        $product_sphinx = new \App\Libs\sphinx\model\Product(new Sphinx($this->sphinx, SphinxTableName::Product));
        $sphinx_result = $product_sphinx->getOne($productId);

        //商品存在状态 1=>存在并上架 2=>存在但下架 3=>已被删除
        $status = !$sphinx_result ? \App\Libs\sphinx\model\Product::EXIST_NO : ($sphinx_result['status'] == \App\Libs\sphinx\model\Product::STATUS_ON ? \App\Libs\sphinx\model\Product::EXIST_YES_ON : \App\Libs\sphinx\model\Product::EXIST_YES_OFF);

        $mapper = new Mapper($this->getDI()->getShared(Mongo::database));
        /**
         * @var \App\Libs\product\structure\Product $product
         */
        $product = $mapper->mongoToProduct((int)$this->request->get('Id', 'int'));

        if ($mongoId) {
            //订单到详情
            $product = $mapper->mongoIdToProduct($mongoId);
        }

        if ($status === \App\Libs\sphinx\model\Product::EXIST_NO) {
            if (!$product) {
                throw new LogicException('商品不存在', Status::BadRequest);
            }
            if ($mongoId) {
                $product->Sku = [];
                $product->Properties = [];
            }
        }
        switch ($status) {
            case \App\Libs\sphinx\model\Product::EXIST_NO:
                if (!$product) {
                    throw new LogicException('商品不存在', Status::BadRequest);
                }
                if ($mongoId) {
                    $product->Sku = [];
                    $product->Properties = [];
                }
                break;
            case \App\Libs\sphinx\model\Product::EXIST_YES_OFF:
                $product->Sku = [];
                $product->Properties = [];
                break;
            default:
                $productUnit = $this->modelsManager->createBuilder()
                    ->columns(['PU.Id', 'PU.Number', 'S.Status', 'S.Stock'])
                    ->addFrom(ProductUnit::class, 'PU')
                    ->leftJoin(ProductUnitStatus::class, 'S.ProductUnitId=PU.Id', 'S')
                    ->where('PU.ProductId=:ProductId:', ['ProductId' => $product->Id])
                    ->getQuery()->execute();
                $productUnit_tmp = [];
                if (count($productUnit->toArray())) {
                    foreach ($productUnit as $value) {
                        $productUnit_tmp[$value['Id']] = ['Status' => $value['Status'], 'Stock' => $value['Stock']];
                    }
                }
        }

        $sku = [];
        if ($product->Sku) {
            foreach ($product->Sku as $k => $item) {
                if (isset($productUnit_tmp[$item->Id]['Status']) && ($productUnit_tmp[$item->Id]['Status'] == ProductUnitStatus::STATUS_ON)) {
                    $unit['Id'] = $item->Id;
                    $unit['Price'] = $auth['HospitalId'] == $auth['OrganizationId'] ? $item->Price : $item->PriceForSlave;
                    $unit['Postage'] = $item->Postage;
                    $unit['IsDefault'] = $item->IsDefault;
                    $unit['Status'] = $productUnit_tmp[$item->Id]['Status'];
                    $unit['Stock'] = $productUnit_tmp[$item->Id]['Stock'];
                    $unit['Images'] = $item->Images;
                    $unit['PropertyIds'] = $item->PropertyIds;
                    $sku[] = $unit;
                }
            }
        }
        $attribute_data = Attribute::find([
            'conditions' => 'ProductCategoryId=?0',
            'bind'       => [ProductCategory::DRUG],
        ]);
        $attribute_data_tmp = [];
        foreach ($attribute_data as $datum) {
            $attribute_data_tmp[$datum->Id] = $datum->Name;
        }
        $attributes = [];
        foreach ($product->Attributes as $att) {
            $attribute['Name'] = $attribute_data_tmp[$att->Id];
            $attribute['Value'] = $att->Value;
            $attributes[] = $attribute;
        }
        $product->Sku = $sku;
        $product->Attributes = $attributes;
        $product->Status = $status;
        $product->OrganizationName = Organization::findFirst(sprintf('Id=%d', $product->OrganizationId))->Name;
        $this->response->setJsonContent($product);
    }

    /**
     * 买家商品列表
     */
    public function buyListAction()
    {
        $auth = $this->session->get('auth');
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $sphinx = new Sphinx($this->sphinx, SphinxTableName::Product);
        $sphinx_query = $sphinx->columns('mongoid')->where('=', ProductUnitStatus::STATUS_ON, 'status')->andWhere('!=', '', 'mongoid');
        //商品名称
        if (isset($data['Name']) && !empty($data['Name'])) {
            $sphinx_query->match($data['Name'], 'name');
        }
        //厂家名称
        if (isset($data['Manufacturer']) && !empty($data['Manufacturer'])) {
            $sphinx_query->match($data['Manufacturer'], 'Manufacturer');
        }
        $count = count($sphinx_query->fetchAll());
        $totalPage = ceil($count / $pageSize);
        $result = $sphinx_query->limit($page, $pageSize)->fetchAll();
        if ($result) {
            $mongoids = array_filter(array_column($result, 'mongoid'));
            $mapper = new Mapper($this->getDI()->getShared(Mongo::database));
            $results = $mapper->productMongoList($mongoids);
            $datas = [];
            if (count($results)) {
                foreach ($results as $k => $result) {
                    $datas[$k]['Id'] = $result->Id;
                    $datas[$k]['Name'] = $result->Name;
                    $datas[$k]['Image'] = $result->Image;
                    $datas[$k]['Manufacturer'] = $result->Manufacturer;
                    foreach ($result->Sku as $datum) {
                        if ($datum->IsDefault == ProductUnit::IS_DEFAULT_YES) {
                            $datas[$k]['SkuId'] = $datum->Id;
                            $datas[$k]['Price'] = $auth['HospitalId'] == $auth['OrganizationId'] ? $datum->Price : $datum->PriceForSlave;
                            $datas[$k]['Postage'] = $datum->Postage;
                            $datas[$k]['PropertyIds'] = array_column((array)$datum->PropertyIds, 'PropertyValueName');
                            continue;
                        }
                    }
                }
            }
        } else {
            $datas = [];
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
        $this->response->setJsonContent($result);
    }
}