<?php

namespace ShoppingFeed\Manager\Helper;

use Magento\Framework\App\Helper\AbstractHelper;

class JsonSerializer extends AbstractHelper {

    /**
     * @param $data
     * @return string
     */
    public function serialize($data)
    {
        $result = json_encode($data);
        if (false === $result) {
            throw new \InvalidArgumentException('Unable to serialize value.');
        }
        return $result;
    }

    /**
     * @param $string
     * @return mixed
     */
    public function unserialize($string)
    {
        $result = json_decode($string, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Unable to unserialize value.');
        }
        return $result;
    }

}