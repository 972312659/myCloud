<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/8/16
 * Time: 14:50
 */

namespace App\Exceptions;


class LogicException extends \Exception implements \JsonSerializable
{
    /**
     * LogicException constructor.
     * @param string $message
     * @param int $code
     * @param \Exception|null $previous
     */
    public function __construct(string $message, int $code, \Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array
     */
    public function jsonSerialize(): array
    {
        return ['message' => $this->message];
    }
}