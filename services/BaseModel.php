<?php

class BaseModel
{
    protected $mysql;
    protected $baseService;

    protected $callType;
    public $logDir;

    public function __construct($mysql, BaseService $baseService)
    {
        $this->mysql = $mysql;
        $this->baseService = $baseService;
    }

    public function setCallType($type) {
        $this->callType = $type;
    }
}
