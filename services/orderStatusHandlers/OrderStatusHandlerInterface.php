<?php

interface OrderStatusHandlerInterface
{
    public function handle($od_id, array $params, bool $useTransaction);
}
