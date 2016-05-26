<?php


namespace EntityOperator\Worker;


use EntityOperator\Command\CommandInterface;

interface WorkerInterface
{
    const PARAM_TABLEID = "tableId";
    const PARAM_ACTIONS_MAP = "map";

    public function execute(CommandInterface $command);

}