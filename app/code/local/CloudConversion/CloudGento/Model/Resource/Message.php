<?php

class CloudConversion_CloudGento_Model_Resource_Message extends Mage_GiftMessage_Model_Resource_Message
{

    /**
     * Import gift message
     *
     * @param array $data columns to import
     * @return inserted id
     */
    public function import($data)
    {
        $write = $this->_getWriteAdapter();
        $write->insertArray($this->getMainTable(), array_keys($data), array($data));
        return $write->lastInsertId();
    }

}
