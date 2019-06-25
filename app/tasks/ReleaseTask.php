<?php

use App\Libs\Feature;
use Phalcon\Cli\Task;
use App\Models\Action;

class ReleaseTask extends Task
{
    const Anonymous = 'Anonymous';

    const Authorize = 'Authorize';

    /**
     * 发布时扫描所有接口并更新
     */
    public function featureAction()
    {
        $sql = 'INSERT INTO `Action`(`Controller`,`Action`, `FeatureId`, `Type`,`Discard`) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE `Type`=?, `Discard`=0';
        try {
            $this->db->begin();
            $this->db->update('Action', ['Discard'], [1]);
            echo "正在扫描接口\n";
            $items = Feature::allActions();
            echo "接口处理中\n";
            foreach ($items as $controller => $actions) {
                foreach ($actions as $action) {
                    $ref = $this->annotations->get($controller);
                    $classAnnotations = $ref->getClassAnnotations();
                    $methodAnnotations = $this->annotations->getMethod($controller, $action->name);
                    $needAuthorize = Action::Authorize;
                    if ($classAnnotations && $classAnnotations->has(self::Anonymous)) {
                        $needAuthorize = Action::Anonymous;
                    }
                    if ($classAnnotations && $classAnnotations->has(self::Authorize)) {
                        $needAuthorize = Action::Authorize;
                    }
                    if ($methodAnnotations->has(self::Anonymous)) {
                        $needAuthorize = Action::Anonymous;
                    }
                    if ($methodAnnotations->has(self::Authorize)) {
                        $needAuthorize = Action::Authorize;
                    }
                    echo "处理 $controller->{$action->name}\n";
                    $this->db->execute($sql, [$controller, $action->name, null, $needAuthorize, 0, $needAuthorize]);
                }
            }
            $this->db->commit();
            echo "接口处理完成\n";
        } catch (Exception $exception) {
            $this->db->rollback();
            echo $exception->getMessage() . "\n";
        }
    }
}