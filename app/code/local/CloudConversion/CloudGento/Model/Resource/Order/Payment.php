<?php

class CloudConversion_CloudGento_Model_Resource_Order_Payment extends Mage_Sales_Model_Resource_Order_Payment
{
    public function import($data)
    {
        $write = $this->_getWriteAdapter();
        $write->insertArray($this->getMainTable(), array_keys($data), array($data));
        return $write->lastInsertId();
    }
}