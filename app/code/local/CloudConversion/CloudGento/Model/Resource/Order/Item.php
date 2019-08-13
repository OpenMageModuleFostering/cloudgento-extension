<?php

class CloudConversion_CloudGento_Model_Resource_Order_Item extends Mage_Sales_Model_Resource_Order_Item
{
    public function import($data)
    {
        $write = $this->_getWriteAdapter();
        $write->insertArray($this->getMainTable(), array_keys($data), array($data));
        return $write->lastInsertId();
    }
    
    /**
     * Load product id by sku and product type
     *
     * @param  $sku
     * @param  $productType
     * @return array
     */
    public function getProductIdBySku($sku, $productType)
    {
        $read = $this->_getReadAdapter();
        $select = $read->select()
                ->from($this->getTable('catalog/product'), 'entity_id')
                ->where('sku = ?', $sku)
                ->where('type_id = ?', $productType);

        return $read->fetchRow($select);
    }

    /**
     * Update relations between order items
     *
     * @param int $itemId
     * @param int $parentItemId
     * @return void
     */
    public function updateParentRelation($itemId, $parentItemId)
    {
        $write = $this->_getWriteAdapter();
        $write->update($this->getMainTable(), array('parent_item_id' => $parentItemId), array('item_id = ? ' => $itemId));
    }

}
