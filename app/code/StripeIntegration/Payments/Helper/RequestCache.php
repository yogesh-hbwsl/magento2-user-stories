<?php

namespace StripeIntegration\Payments\Helper;

class RequestCache
{
    private $cache = [];

    public function get($key)
    {
        return isset($this->cache[$key]) ? $this->cache[$key] : null;
    }

    public function set($key, $value)
    {
        $this->cache[$key] = $value;
    }

    public function delete($key)
    {
        unset($this->cache[$key]);
    }

    public function clear()
    {
        $this->cache = [];
    }
}