<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/8/15
 * Time: 17:36
 */

namespace App\Admin\Controllers;
use App\Models\Staff;
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
    public $staff;

    public function inject()
    {
        $auth = $this->session->get('auth');
        if ($auth !== null && $auth['Id']) {
            $this->staff = Staff::findFirst($auth['Id']);
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