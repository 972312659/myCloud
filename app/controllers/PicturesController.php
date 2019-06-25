<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/10
 * Time: 上午11:33
 */

namespace App\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\OrganizationBanner;
use App\Models\Transfer;
use App\Models\TransferPicture;

class PicturesController extends Controller
{
    /**
     * banner图
     * @throws LogicException
     * @throws ParamException
     */
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $count = OrganizationBanner::count("OrganizationId={$this->user->OrganizationId} and Type=1");
                if ($count >= 5) {
                    throw new LogicException('App端Banner图不需要再添加', Status::BadRequest);
                }
                $data['OrganizationId'] = $this->user->OrganizationId;
                $data['Type'] = OrganizationBanner::PLATFORM_APP;
                $data['Updated'] = time();
                if (!isset($data['Sort']) || empty($data['Sort'])) {
                    $data['Sort'] = $count + 1;
                }
                $banner = new OrganizationBanner();
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $banner = OrganizationBanner::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$banner || (int)$this->user->OrganizationId !== (int)$banner->OrganizationId) {
                    throw $exception;
                }
                unset($data['OrganizationId']);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if (!isset($data['Choice']) || ($data['Choice'] != OrganizationBanner::CHOICE_CONTENT && $data['Choice'] != OrganizationBanner::CHOICE_URL)) {
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
     * 删除banner
     */
    public function delBannerAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isDelete()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException('请登录', Status::Unauthorized);
            }
            $banner = OrganizationBanner::findFirst(sprintf('Id=%d', $this->request->getPut('Id', 'int')));
            if (!$banner || $banner->OrganizationId != $auth['OrganizationId']) {
                throw $exception;
            }
            $banner->delete();
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 完成转诊添加图片
     */
    public function transferAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $data = $this->request->getPut();
                if (empty($data['Images']) || !count($data['Images']) || !isset($data['Images']) || !is_array($data['Images'])) {
                    if (isset($data['TherapiesExplain']) && empty($data['TherapiesExplain'])) {
                        throw new LogicException('必须添加图片或者治疗方案补充说明', Status::BadRequest);
                    } elseif (isset($data['ReportExplain']) && empty($data['ReportExplain'])) {
                        throw new LogicException('必须添加图片或者检查报告补充说明', Status::BadRequest);
                    } elseif (isset($data['DiagnosisExplain']) && empty($data['DiagnosisExplain'])) {
                        throw new LogicException('必须添加图片或者诊断结论补充说明', Status::BadRequest);
                    } elseif (isset($data['FeeExplain']) && empty($data['FeeExplain'])) {
                        throw new LogicException('必须添加图片或者收费补充说明', Status::BadRequest);
                    } elseif (!isset($data['TherapiesExplain']) && !isset($data['ReportExplain']) && !isset($data['DiagnosisExplain']) && !isset($data['FeeExplain'])) {
                        throw new LogicException('必须添加图片或者描述', Status::BadRequest);
                    }
                }
                $this->db->begin();
                $transfer = Transfer::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$transfer || (int)$this->user->OrganizationId !== (int)$transfer->AcceptOrganizationId) {
                    throw $exception;
                }
                $whiteList = ['TherapiesExplain', 'ReportExplain', 'DiagnosisExplain', 'FeeExplain'];
                if ($transfer->save($data, $whiteList) === false) {
                    $exception->loadFromModel($transfer);
                    throw $exception;
                }
                $oldPictures = TransferPicture::find(['conditions' => 'TransferId=?0 and Type=?1', 'bind' => [$transfer->Id, $data['Type']]]);
                if (count($oldPictures->toArray())) {
                    if ($oldPictures->delete() === false) {
                        $exception->add('Images', '请重新尝试');
                        throw $exception;
                    }
                }
                if (!empty($data['Images']) && count($data['Images']) && isset($data['Images']) && is_array($data['Images'])) {
                    foreach ($data['Images'] as $image) {
                        $transferPicture = new TransferPicture;
                        $transferPicture->TransferId = $transfer->Id;
                        $transferPicture->Image = $image;
                        $transferPicture->Type = $data['Type'];
                        if ($transferPicture->save() === false) {
                            $exception->loadFromModel($transferPicture);
                            throw $exception;
                        }
                    }
                }
                $this->db->commit();
                $this->response->setStatusCode(Status::Created);
                $this->response->setJsonContent($data);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }
}