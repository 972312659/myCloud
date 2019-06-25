<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/8/16
 * Time: 14:59
 */

namespace App\Exceptions;


use Phalcon\Mvc\Model\Message;
use Phalcon\Mvc\ModelInterface;
use Phalcon\Validation\Message\Group;

class ParamException extends \Exception implements \JsonSerializable
{
    protected $messages = [];

    /**
     * ParamException constructor.
     * @param int $code
     * @param ParamException|null $previous
     */
    public function __construct($code, self $previous = null)
    {
        parent::__construct('multiple messages', $code, $previous);
    }

    /**
     * @param Group $messages
     */
    public function loadFromMessage(Group $messages)
    {
        foreach ($messages as $message) {
            /**
             * @var \Phalcon\Validation\MessageInterface $message
             */
            $this->add($message->getField(), $message->getMessage());
        }
    }

    /**
     * @param string $field
     * @param string $message
     */
    public function add(string $field, string $message)
    {
        $this->messages[] = ['field' => $field, 'message' => $message];
    }

    /**
     * @param ModelInterface $model
     * @return array
     */
    public function loadFromModel(ModelInterface $model)
    {
        $this->messages = array_map(function (Message $item) {
            /**
             * @var \Phalcon\Mvc\Model\MessageInterface $item
             */
            return [
                'field'   => $item->getField(),
                'message' => $item->getMessage(),
            ];
        }, $model->getMessages());
    }

    /**
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->messages;
    }
}