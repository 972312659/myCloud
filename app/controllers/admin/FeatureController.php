<?php

namespace App\Admin\Controllers;

use App\Exceptions\ParamException;
use App\Models\Feature;
use App\Models\OrganizationType;
use App\Models\OrganizationTypeFeature;

class FeatureController extends Controller
{
    /**
     * 获取所有机构类型
     */
    public function typesAction()
    {
        $this->response->setJsonContent(OrganizationType::find());
    }

    /**
     * 添加机构类型
     * @throws ParamException
     */
    public function addTypeAction()
    {
        $e = new ParamException(400);
        $type = new OrganizationType();
        $type->Name = $this->request->get('Name');
        if (!$type->save()) {
            $e->loadFromModel($type);
            throw $e;
        }
        $this->response->setJsonContent([
            'message' => '添加成功',
        ]);
    }

    /**
     * 获取所有功能
     */
    public function listAction()
    {
        $this->response->setJsonContent(Feature::tree(0));
    }

    /**
     * 根据机构类型Id设置默认的功能
     */
    public function setFeaturesAction()
    {
        $e = new ParamException(400);
        $id = $this->request->get('TypeId');
        $features = $this->request->get('FeatureIds', null, []);
        $sql = 'delete from OrganizationTypeFeature where OrganizationTypeId=?';
        try {
            $this->db->begin();
            $this->db->execute($sql, [$id]);
            foreach ($features as $featureId) {
                $feature = new OrganizationTypeFeature();
                $feature->FeatureId = $featureId;
                $feature->OrganizationTypeId = $id;
                if (!$feature->save()) {
                    $e->loadFromModel($feature);
                    throw $e;
                }
            }
            $this->db->commit();
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
        $this->response->setJsonContent([
            'message' => '设置成功',
        ]);
    }
}