<?php

class CloudConversion_CloudGento_Model_Order_Api extends Mage_Sales_Model_Order_Api
{

    /**
     * Import orders
     * Available via SOAP v2 only at the moment
     */
    public function import($orders)
    {
        $this->_fault('invalid_protocol');
    }

}
