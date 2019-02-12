<?php
namespace MarsLib\Server\Http;

class Factory
{

    /**
     * @var $framework string
     * @return \MarsLib\Server\Http\SwooleYaf
     * @throws \Exception
     */
    static function getServer($framework = 'yaf')
    {
        /** @var \MarsLib\Server\Http\SwooleYaf $class */
        $class = "\\MarsLib\\Server\\Http\\Swoole" . ucfirst($framework);
        $exist = class_exists($class);
        if(!$exist) {
            throw new \Exception("{$framework} not support");
        }
        return $class::getInstance();
    }
}