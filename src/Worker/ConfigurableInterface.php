<?php


namespace DeltaPhp\Operator\Worker;


interface ConfigurableInterface
{
    public function setConfig($config);
    
    public function addConfig($config);

    public function getConfig($path, $default = null);

}
