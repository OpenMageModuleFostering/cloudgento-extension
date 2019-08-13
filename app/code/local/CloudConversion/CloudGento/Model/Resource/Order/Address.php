<?php

class CloudConversion_CloudGento_Model_Resource_Order_Address extends Mage_Sales_Model_Resource_Order_Address
{
    public function import($data)
    {
        $write = $this->_getWriteAdapter();
        $region = Mage::getModel('directory/region')->getCollection()->addFieldToFilter('code', $data['region'])->getFirstItem();
        if($region->getRegionId()) {
            $data['region'] = $region->getDefaultName();
        }
        $write->insertArray($this->getMainTable(), array_keys($data), array($data));
        return $write->lastInsertId();
    }
}