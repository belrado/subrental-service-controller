<?php

class CustomException extends Exception
{
    protected $errorCode;
    protected $errorData;

    public function __construct($message, $errorCode = "0009", $errorData = null, $code = 0, Exception $previous = null)
    {
        $this->errorCode = $errorCode;
        $this->errorData = $errorData;
        parent::__construct($message, $code, $previous);
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function getErrorData()
    {
        return $this->errorData ?? [];
    }
}

