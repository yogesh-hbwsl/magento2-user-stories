<?php

namespace StripeIntegration\Payments\Helper;

class Compare
{
    public $lastReason = '';

    // Returns true if the object values are different than $expectedValues
    public function isDifferent($object, array $expectedValues)
    {
        return !$this->isSame($object, $expectedValues);
    }

    // Returns true if the object values are the same as $expectedValues
    public function isSame($object, array $expectedValues)
    {
        try
        {
            $this->lastReason = '';

            $values = json_decode(json_encode($object), true);
            if (!is_array($values))
                throw new \Exception("is_array");

            foreach ($expectedValues as $key => $value)
            {
                $this->compare($values, $expectedValues, $key);
            }

            return true;
        }
        catch (\Exception $e)
        {
            $this->lastReason = $e->getMessage();

            return false;
        }
    }

    public function compare(array $values, array $expectedValues, string $key)
    {
        if ($expectedValues[$key] === "unset")
        {
            if (isset($values[$key]))
                throw new \Exception($key . " should not be set");
            else
                return;
        }
        else if (!isset($values[$key]))
            throw new \Exception($key . " is not set");

        if (is_array($expectedValues[$key]))
        {
            if (!is_array($values[$key]))
                throw new \Exception($key);

            foreach ($expectedValues[$key] as $k => $value)
            {
                $this->compare($values[$key], $expectedValues[$key], $k);
            }
        }
        else
        {
            if ($expectedValues[$key] != $values[$key])
                throw new \Exception($key);
        }
    }

    public function areArrayValuesTheSame(array $array1, array $array2)
    {
        sort($array1);
        sort($array2);
        return ($array1 == $array2);
    }
}
