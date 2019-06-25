<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/8/17
 * Time: 11:08
 */

namespace App\Plugins;

use Phalcon\DiInterface;
use Phalcon\Events\Event;
use Phalcon\Db\AdapterInterface;
use Phalcon\Logger\Adapter\File;

class DatabaseListener
{
    /**
     * @var DiInterface
     */
    protected $di;

    public function __construct(DiInterface $di)
    {
        $this->di = $di;
    }

    public function beforeQuery(Event $event, AdapterInterface $connection)
    {
        $logger = new File(APP_PATH . '/logs/sql.log');
        $sqlVariables = (array)$connection->getSQLVariables();
        if (count($sqlVariables)) {
            $logger->debug($connection->getSQLStatement() . ' [' . implode(', ', $sqlVariables).']');
        } else {
            $logger->debug($connection->getSQLStatement());
        }
    }
}