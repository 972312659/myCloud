<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/8/3
 * Time: 16:28
 */

namespace App\Controllers;

use App\Models\User;
use Phalcon\Paginator\AdapterInterface;

/**
 * Class Controller
 *
 * @property \Phalcon\Config\Adapter\Ini $config
 * @property \Phalcon\Mvc\Dispatcher $dispatcher
 * @property \Phalcon\Http\Request $request
 * @property \Phalcon\Http\Response $response
 * @property \Phalcon\Db\Adapter\Pdo\Mysql $db
 * @property \Phalcon\Mvc\Router $router
 * @property \Phalcon\Mvc\Url $url
 * @property \Phalcon\Logger\Adapter\File $logger
 * @property \Redis $redis
 * @property \App\Libs\Session $session
 * @property \Pheanstalk\Pheanstalk $queue
 * @property \Qiniu\Auth $qiniu
 * @property \App\Libs\Sms $sms
 * @property \App\Libs\Push $push
 * @property \PDO $sphinx
 * @property \EasyWeChat\Payment\Application $wxpay
 * @property \App\Enums\PaymentChannel $channels
 * @property \FluentPDO $fluent
 */
class Controller extends \Phalcon\Mvc\Controller
{
    /**
     * @var User
     */
    public $user = null;

    public function inject()
    {
        $auth = $this->session->get('auth');
        if ($auth !== null && $auth['Id']) {
            $this->user = User::findFirst($auth['Id']);
            if (isset($auth['OrganizationId'])) {
                $this->user->OrganizationId = $auth['OrganizationId'];
                $this->user->Role = $auth['Role'];
                $this->user->UserId = $auth['Id'];
                $this->user->Switch = $auth['Switch'];
            }
        }
    }

    public function outputPagedJson(AdapterInterface $adapter, $data = null)
    {
        $pager = $adapter->getPaginate();
        $this->response->setJsonContent([
            'Data'     => $data ?: $pager->items,
            'PageInfo' => [
                'TotalPage' => $pager->total_pages,
                'Page'      => $pager->current,
                'Count'     => $pager->total_items,
            ],
        ]);
    }
}
