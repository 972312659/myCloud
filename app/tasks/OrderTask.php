<?php

use Phalcon\Cli\Task;
use App\Models\InteriorTrade;
use App\Libs\interiorTrade\UnPass;

class OrderTask extends Task
{
    /**
     * 取消超过24小时的订单审核单
     */
    public function interiorTradeTimeoutAction()
    {
        //1次处理1000单
        $i = 1000;
        $created = time() - 86400; //24小时前的时间戳
        $start = 0;
        do {
            $trades = InteriorTrade::find([
                'conditions' => 'Style = ?0 AND Status = ?1 AND Created <= ?2 AND Id > ?3',
                'bind' => [InteriorTrade::STYLE_PRODUCT, InteriorTrade::STATUS_WAIT, $created, $start],
                'limit' => $i,
                'order' => 'Id ASC'
            ]);

            foreach ($trades as $trade) {
                try {
                    $this->db->begin();

                    $unpass = new UnPass($trade);
                    $unpass->product();

                    $this->db->commit();
                } catch (Exception $e) {
                    $this->db->rollback();
                    $this->getDI()->get('logger')->error($e->getMessage());
                }
            }
            $start = end($trades)['Id'];
            $count = count($trades);
        } while ($count >= $i);
    }
}
