<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/8/15
 * Time: 18:36
 */

namespace App\Admin\Controllers;

use App\Exceptions\LogicException;
use App\Enums\Status;
use App\Libs\Push;
use App\Models\Organization;
use App\Models\Transfer;
use App\Models\User;
use Phalcon\Http\Response;
use Phalcon\Mvc\Model\Query\Builder;

class IndexController extends Controller
{
    /**
     * @Anonymous
     * @throws LogicException
     */
    public function indexAction()
    {
        throw new LogicException('test', Status::BadRequest);
    }

    /**
     * @Anonymous
     * @return Response
     */
    public function notFoundAction()
    {
        $response = new Response();
        $response->setStatusCode(Status::NotFound);
        $response->setJsonContent([
            'message' => 'Resource doesn\'t exists.',
        ]);
        return $response;
    }

    /**
     * 首页统计
     */
    public function infoAction()
    {
        $real = filter_var($this->request->get('Real'), FILTER_VALIDATE_BOOLEAN);
        // 查询机构
        $oq = $this->fluent->from('Organization')
            ->groupBy('Type')
            ->select(null)->select('Type,COUNT(Id) AS Amount');
        $oq = $real ? $oq->where('Fake=?', 0) : $oq;

        // 从账单查询充值金额
        $cq = $this->fluent->from('Bill b')
            ->innerJoin('Organization o ON b.OrganizationId=o.Id')
            ->groupBy('IsMain')
            ->select(null)->select('o.IsMain, SUM(b.Fee) AS Money')
            ->where('b.Type=?', 1);
        $cq = $real ? $cq->where('o.Fake=?', 0) : $cq;

        // 从账单查询提现金额
        $wq = $this->fluent->from('Bill b')
            ->innerJoin('Organization o ON b.OrganizationId=o.Id')
            ->groupBy('IsMain')
            ->select(null)->select('o.IsMain, SUM(b.Fee) AS Money')
            ->where('b.Type=?', 2);
        $wq = $real ? $wq->where('o.Fake=?', 0) : $wq;

        // 查询转诊单
        $tq = $this->fluent->from('Transfer')
            ->groupBy('`Status`')
            ->select(null)->select('`Status`,COUNT(*) AS Amount, SUM(Cost) AS Money');
        $tq = $real ? $tq->where('IsFake=?', 0) : $tq;

        $this->response->setJsonContent([
            'Organizations' => $oq->fetchAll(),
            'Charges'       => $cq->fetchAll(),
            'Withdraws'     => $wq->fetchAll(),
            'Transfers'     => $tq->fetchAll(),
        ]);
    }

    public function getQiniuTokenAction()
    {
        // 空间名  https://developer.qiniu.io/kodo/manual/concepts https://avatars.store.100cbc.com
        $bucket = 'referral';
        // 生成上传Token
        $token = $this->qiniu->uploadToken($bucket, null, 3600);
        $this->response->setJsonContent(['uptoken' => $token]);
    }
}