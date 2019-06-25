<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/21
 * Time: 上午11:29
 * Title: 广告宣传
 */

namespace App\Admin\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\Organization;
use App\Models\OrganizationBanner;

class MarketingController extends Controller
{
    /**
     * 添加banner图
     * @throws LogicException
     * @throws ParamException
     */
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                if (empty($data['Type']) || !is_numeric($data['Type'])) {
                    throw new LogicException('请填写正确的类型', Status::BadRequest);
                }
                if (in_array((int)$data['Type'], [OrganizationBanner::PLATFORM_APP, OrganizationBanner::PLATFORM_PC])) {
                    $peach = Organization::PEACH;
                    $count = OrganizationBanner::count("OrganizationId={$peach} and Type={$data['Type']}");
                    switch ($data['Type']) {
                        case OrganizationBanner::PLATFORM_APP:
                            if ($count >= 3) {
                                throw new LogicException('App端Banner图不需要再添加', Status::BadRequest);
                            }
                            break;
                        default:
                            if ($count >= 2) {
                                throw new LogicException('PC端Banner图不需要再添加', Status::BadRequest);
                            }
                    }
                }
                $data['OrganizationId'] = Organization::PEACH;
                $banner = new OrganizationBanner();
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $banner = OrganizationBanner::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$banner) {
                    $banner = new OrganizationBanner();
                    $data['OrganizationId'] = Organization::PEACH;
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if (!isset($data['Choice']) && $data['Choice'] != OrganizationBanner::CHOICE_CONTENT && $data['Choice'] != OrganizationBanner::CHOICE_URL) {
                $data['Choice'] = null;
            }
            if ($banner->save($data) === false) {
                $exception->loadFromModel($banner);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * banner列表
     */
    public function listAction()
    {
        $data = $this->request->getPost();
        $banners = OrganizationBanner::query()
            ->where(sprintf('OrganizationId=%d', Organization::PEACH))
            ->andWhere("Type=:Type:")
            ->bind(['Type' => $data['Type']])
            ->execute();
        $this->response->setJsonContent($banners);
    }

    public function deleteAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isDelete()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $banner = OrganizationBanner::findFirst(sprintf('Id=%d', $this->request->getPut('Id')));
            if (!$banner || $banner->OrganizationId !== Organization::PEACH) {
                throw $exception;
            }
            $banner->delete();
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function readBannerAction()
    {
        $this->response->setJsonContent(OrganizationBanner::findFirst(['conditions' => 'Id=?0 and OrganizationId=?1', 'bind' => [$this->request->get('Id'), Organization::PEACH]]) ?: []);
    }
}