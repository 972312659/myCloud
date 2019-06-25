<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/11/1
 * Time: 上午10:43
 */

namespace App\Admin\Controllers;


use App\Enums\Mongo;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\product\Mapper;
use App\Libs\Sphinx;
use App\Libs\sphinx\TableName as SphinxTableName;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Attribute;
use App\Models\ProductAttribute;
use App\Models\ProductPicture;
use App\Models\ProductProperty;
use App\Models\ProductPropertyValue;
use App\Models\ProductUnit;
use App\Models\ProductUnitPicture;
use App\Models\ProductUnitStatus;
use App\Models\Property;
use App\Models\ProductUnitProductPropertyValue;
use App\Models\StaffProductAuditLog;
use Phalcon\Paginator\Adapter\QueryBuilder;

class ProductController extends Controller
{
    /**
     * 审核商品列表
     */
    public function listAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns(['P.Id', 'P.Name', 'P.Manufacturer', 'P.Audit', 'P.Updated', 'O.Name as OrganizationName'])
            ->addFrom(Product::class, 'P')
            ->leftJoin(Organization::class, 'O.Id=P.OrganizationId', 'O');
        //状态
        if (isset($data['Audit']) && is_numeric($data['Audit'])) {
            $query->andWhere('P.Audit=:Audit:', ['Audit' => $data['Audit']]);
        } else {
            $query->inWhere('P.Audit', [Product::AUDIT_WAIT, Product::AUDIT_ON, Product::AUDIT_REFUSE]);
        }
        //商家
        if (isset($data['OrganizationName']) && !empty($data['OrganizationName'])) {
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $name = $sphinx->match($data['OrganizationName'], 'name')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->inWhere('O.Id', $ids);
            } else {
                $query->inWhere('O.Id', [-1]);
            }
        }
        //厂家名称
        if (isset($data['Manufacturer']) && !empty($data['Manufacturer'])) {
            $sphinx = new Sphinx($this->sphinx, SphinxTableName::Product);
            $name = $sphinx->match($data['Manufacturer'], 'manufacturer')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->inWhere('P.Id', $ids);
            } else {
                $query->inWhere('P.Id', [-1]);
            }
        }
        //商品名
        if (isset($data['Name']) && !empty($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, SphinxTableName::Product);
            $name = $sphinx->match($data['Name'], 'submitname')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->inWhere('P.Id', $ids);
            } else {
                $query->inWhere('P.Id', [-1]);
            }
        }
        $query->orderBy('P.Updated desc');
        $paginator = new QueryBuilder(
            [
                "builder" => $query,
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $this->outputPagedJson($paginator);
    }

    /**
     * 查看商品详情
     */
    public function readAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            /**
             * @var Product $product
             */
            $product = Product::findFirst([
                'conditions' => 'Id=?0',
                'bind'       => [$this->request->get('Id')],
            ]);
            if (!$product) {
                throw $exception;
            }
            if ($product->Audit === Product::AUDIT_RECALL) {
                throw new LogicException('商品已撤回', Status::BadRequest);
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
                    'PropertyIds'   => $productUnitProductPropertyValues_new[$unit['Id']],
                    'Images'        => $skuImages_new[$unit['Id']],
                ];
            }
            //Attributes
            $result['Attributes'] = ProductAttribute::query()
                ->columns(['AttributeId as Id', 'Value', 'A.Name'])
                ->leftJoin(Attribute::class, 'A.Id=AttributeId', 'A')
                ->where(sprintf('ProductId=%d', $product->Id))
                ->execute()->toArray();
            $this->response->setJsonContent($result);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 审核
     */
    public function auditAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPut();
            if (!isset($data['Audit']) || !in_array($data['Audit'], [Product::AUDIT_ON, Product::AUDIT_REFUSE])) {
                throw new LogicException('参数错误', Status::MethodNotAllowed);
            }
            /**
             * @var Product $product
             */
            $product = Product::findFirst(sprintf('Id=%d', $data['Id']));
            if (!$product) {
                throw $exception;
            }
            if ($product->Audit !== Product::AUDIT_WAIT) {
                throw new LogicException('产品已撤销，待供应商提交之后重新审核', Status::MethodNotAllowed);
            }
            if ($data['InTime'] < $product->Updated) {
                throw new LogicException('产品有更改，刷新之后重新审核', Status::BadRequest);
            }
            $product->Audit = $data['Audit'];
            if (!$product->save()) {
                $exception->loadFromModel($product);
                throw $exception;
            }
            $product->refresh();
            //如果第一次审核就将sku状态全部设置为上架
            $mapper = new Mapper($this->getDI()->getShared(Mongo::database));
            if (!$mapper->getMongoId($product->Id)) {
                $productUnits = ProductUnit::find([
                    'conditions' => 'ProductId=?0',
                    'bind'       => [$product->Id],
                ])->toArray();
                $productUnitStatues = ProductUnitStatus::query()
                    ->inWhere('ProductUnitId', array_column($productUnits, 'Id'))
                    ->execute();
                foreach ($productUnitStatues as $productUnitStatus) {
                    /**
                     * @var ProductUnitStatus $productUnitStatus
                     */
                    $productUnitStatus->Status = ProductUnitStatus::STATUS_ON;
                    if (!$productUnitStatus->save()) {
                        $exception->loadFromModel($productUnitStatus);
                        throw $exception;
                    }
                }
            }
            //如果审核通过记录新版本
            $mongoId = null;
            if ($product->Audit === Product::AUDIT_ON) {
                $mongoId = $mapper->createMongo($product->Id);
            }
            //记录日志
            /**
             * @var StaffProductAuditLog $staffProductAuditLog
             */
            $staffProductAuditLog = new StaffProductAuditLog();
            $staffProductAuditLog->ProductId = $product->Id;
            $staffProductAuditLog->Audit = $product->Audit;
            $staffProductAuditLog->StaffId = $this->staff->Id;
            $staffProductAuditLog->StaffName = $this->staff->Name;
            $staffProductAuditLog->LogTime = time();
            if ($product->Audit === Product::AUDIT_ON) $staffProductAuditLog->MongoId = $mongoId;
            if (!$staffProductAuditLog->save()) {
                $exception->loadFromModel($staffProductAuditLog);
                throw $exception;
            }
            //写入sphinx
            if ($product->Audit === Product::AUDIT_ON) {
                $productSphinx = new \App\Libs\sphinx\model\Product(new Sphinx($this->sphinx, SphinxTableName::Product));
                if (!$productSphinx->save($product, $mongoId)) {
                    throw new LogicException('缓存错误', Status::BadRequest);
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
}