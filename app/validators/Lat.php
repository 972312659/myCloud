<?php

namespace App\Validators;

use Phalcon\Validation;
use Phalcon\Validation\Message;
use Phalcon\Validation\Validator;

class Lat extends Validator
{
    public function validate(Validation $validator, $attribute)
    {
        $value = $validator->getValue($attribute);
        if (!preg_match('/^[\-\+]?[0-9]?[0-9]\.\d{1,8}$/', $value) || abs($value) > 90) {
            $message = $this->getOption('message');
            if (!$message) {
                $message = "Invalid Lat";
            }
            $validator->appendMessage(
                new Message($message, $attribute, "Lat")
            );
            return false;
        }
        return true;
    }
}
