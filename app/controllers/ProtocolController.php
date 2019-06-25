<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/11/16
 * Time: 下午3:22
 */

namespace App\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\OrganizationAndProtocol;
use App\Models\Protocol;

class ProtocolController extends Controller
{
    /**
     * 判断是否签订协议
     */
    public function judgeAction()
    {
        $organizationAndProtocol = OrganizationAndProtocol::findFirst([
            'conditions' => 'OrganizationId=?0 and ProtocolId=?1',
            'bind'       => [$this->session->get('auth')['OrganizationId'], $this->request->get('Id', 'int') ?: Protocol::NAME_MEDICAL_ALLIANCE_COOPERATION_AGREEMENT_Id],
        ]);
        $this->response->setJsonContent(['Judge' => $organizationAndProtocol ? true : false]);
    }

    public function readAction()
    {
        $this->response->setJsonContent(Protocol::findFirst(sprintf('Id=%d', $this->request->get('Id', 'int') ?: Protocol::NAME_MEDICAL_ALLIANCE_COOPERATION_AGREEMENT_Id)));
    }

    /**
     * 机构签订协议
     * @throws ParamException
     */
    public function OrganizationSignAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $protocol = Protocol::findFirst(sprintf('Id=%d', $this->request->get('Id', 'int')));
            if (!$protocol) {
                throw $exception;
            }
            $organizationAndProtocol = new OrganizationAndProtocol();
            $organizationAndProtocol->OrganizationId = $this->session->get('auth')['OrganizationId'];
            $organizationAndProtocol->ProtocolId = $this->request->get('Id', 'int');
            if (!$organizationAndProtocol->save()) {
                $exception->loadFromModel($organizationAndProtocol);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        }
    }
}