<?php

namespace App\Validators;

use Phalcon\Validation;
use Phalcon\Validation\Message;
use Phalcon\Validation\Validator;

class Mobile extends Validator
{
    public function validate(Validation $validator, $attribute)
    {
        $value = $validator->getValue($attribute);
        if (!preg_match('/^1[3456789]\d{9}$/', $value)) {
            $message = $this->getOption('message');
            if (!$message) {
                $message = "Invalid mobile number.";
            }
            $validator->appendMessage(
                new Message($message, $attribute, "Mobile")
            );
            return false;
        }
        return true;
    }
}
