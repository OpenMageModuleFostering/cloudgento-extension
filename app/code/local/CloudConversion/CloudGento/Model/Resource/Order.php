<?php

class CloudConversion_CloudGento_Model_Resource_Order extends Mage_Sales_Model_Resource_Order
{

    /** @var array $_importedItems */
    protected $_importedItems = array();

    /** @var array $_itemsWithParent order items that have parent items */
    protected $_itemsWithParent = array();

    /** @var array $_importWarnings */
    protected $_importWarnings = array();

    /** @var array $_productTypes */
    protected $_productTypes = array();

    public function import($data)
    {
        $write = $this->_getWriteAdapter();
        $write->insertArray($this->getMainTable(), array_keys($data), array($data));
        return $write->lastInsertId();
    }

    /**
     * Update shipping address relation
     *
     * @param int $orderId
     * @param int $addressId
     * @return void
     */
    public function updateShippingAddressId($orderId, $addressId)
    {
        $write = $this->_getWriteAdapter();
        $write->update($this->getMainTable(), array('shipping_address_id' => $addressId), array('entity_id = ? ' => $orderId));
    }

    /**
     * Update billing address relation
     *
     * @param int $orderId
     * @param int $addressId
     * @return void
     */
    public function updateBillingAddressId($orderId, $addressId)
    {
        $write = $this->_getWriteAdapter();
        $write->update($this->getMainTable(), array('billing_address_id' => $addressId), array('entity_id = ? ' => $orderId));
    }

    /**
     * @param int $orderId
     * @param int $childOrderId
     * @param string $childOrderIncrementId
     * @return void
     */
    public function updateChildRelation($orderId, $childOrderId, $childOrderIncrementId)
    {
        $write = $this->_getWriteAdapter();
        $write->update($this->getMainTable(), array('relation_child_id' => $childOrderId, 'relation_child_real_id' => $childOrderIncrementId), array('entity_id = ? ' => $orderId));
    }

    /**
     * @param int $orderId
     * @param int $parentOrderId
     * @param string $parentOrderIncrementId
     * @return void
     */
    public function updateParentRelation($orderId, $parentOrderId, $parentOrderIncrementId)
    {
        $write = $this->_getWriteAdapter();
        $write->update($this->getMainTable(), array('relation_parent_id' => $parentOrderId, 'relation_parent_real_id' => $parentOrderIncrementId), array('entity_id = ? ' => $orderId));
    }

    /**
     * Check if order with given increment id already exists
     *
     * @param int $incrementId
     * @return bool
     */
    public function checkIncrementIdExists($incrementId)
    {
        $adapter = $this->getReadConnection();
        $select = $adapter->select()
                ->from($this->getMainTable(), array("increment_id"))
                ->where('increment_id = ?', $incrementId);
        if ($adapter->fetchOne($select)) {
            return true;
        }
        return false;
    }

    /**
     * Import order in transaction
     *
     * @throws Exception
     * @param array $order
     * @return int
     */
    public function importOrder($order, $decrementInventory = false)
    {
        $store = Mage::app()->getDefaultStoreView();
        /** @var $customer Mage_Customer_Model_Customer */
        $customer = Mage::getModel('customer/customer')
                ->setWebsiteId($store->getWebsiteId())
                ->loadByEmail($order['customer_email']);

        $order['customer_id'] = $customer->getId();
        $origStoreId = $order['store_id'];
        $order['store_id'] = (!isset($order['store_id']) || $order['store_id'] == 0) ? $store->getId() : $order['store_id'];
        $order['quote_id'] = null;

        // Try to associate customer group
        $groupModel = Mage::getModel('customer/group');
        $groupModel->load($order['customer_group_code'], 'customer_group_code');
        if ($groupModel->getId()) {
            $order['customer_group_id'] = $groupModel->getId();
        } else {
            $order['customer_group_id'] = -1;
        }
        unset($order['customer_group_code']);
        // Try to associate customer gender
        $order['customer_gender'] = $this->_associateCustomerGender($order['customer_gender_value']);
        unset($order['customer_gender_value']);

        // If order has custom status - set default status of order state and give warning
        $newStatus = null;
        $oldStatus = null;
        if (!in_array($order['status'], array_keys(Mage::getSingleton('sales/order_config')->getStatuses()))) {
            $newStatus = Mage::getSingleton('sales/order_config')->getStateDefaultStatus($order['state']);
            $oldStatus = $order['status'];
            $order['status'] = $newStatus;
        }

        $addressData = $order['address'];
        $itemsData = $order['items'];
        $paymentData = $order['payment'];
        $historyData = $order['history'];
        unset($order['address']);
        unset($order['items']);
        unset($order['payment']);
        unset($order['history']);
        unset($order['order_id']);

        $this->beginTransaction();
        try {
            $this->_importGiftMessage($order);
            $orderId = $this->import($order);
// todo dispatch the event that updates stats if it exists
//            $orderObject = Mage::getModel('sales/order')->load($orderId);
//            $quoteObject = Mage::getModel('sales/quote')->load($order['quote_id']);
//Mage::log(var_export($orderObject, true));
//            Mage::dispatchEvent(
//    'checkout_submit_all_after',
//    array('order' => $orderObject, 'quote' => $quoteObject)
//);
            if ($newStatus) {
                $this->addImportWarning($orderId, sprintf('Custom status "%s" was not found. Default status "%s" was set instead.', $oldStatus, Mage::getSingleton('sales/order_config')->getStatusLabel($newStatus)));
            }

            if (!$customer->getId()) {
                $this->addImportWarning($orderId, sprintf('Customer with email "%s" was not found', $order['customer_email']));
            }

            $this->_importAddress($orderId, $addressData, $customer->getId());
            $this->_importItems($orderId, $itemsData, $order['state'], $store->getId(), $decrementInventory);
            $this->_restoreItemsRelations();
            $this->_importPayments($orderId, $paymentData);
            $this->_importHistory($orderId, $historyData);

            // Increase or create last_increment_id
            $storeModel = Mage::getModel('core/store')->load($origStoreId);
            if ($storeModel->getId() && is_numeric($order['increment_id'])) {
                $entityType = Mage::getSingleton('eav/config')->getEntityType('order')->getId();
                $entityStoreConfig = Mage::getModel('eav/entity_store')->loadByEntityStore($entityType, $origStoreId);
                if (!$entityStoreConfig->getId()) {
                    $entityStoreConfig
                            ->setEntityTypeId($entityType)
                            ->setStoreId($origStoreId)
                            ->setIncrementPrefix($origStoreId)
                            ->setIncrementLastId($order['increment_id'])
                            ->save();
                } else if ($entityStoreConfig->getIncrementLastId() < $order['increment_id']) {
                    $entityStoreConfig->setIncrementLastId($order['increment_id']);
                    $entityStoreConfig->save();
                }
            }

            $this->commit();
        } catch (Exception $e) {
            $this->rollBack();
            throw $e;
        }
        return $orderId;
    }

    /**
     * Try to associate customer gender attribute from CE
     *
     * @param string $value text value of gender attribute
     * @return null
     */
    protected function _associateCustomerGender($value)
    {
        /** @var $attribute Mage_Eav_Model_Entity_Attribute */
        $attribute = Mage::getModel('eav/entity_attribute')
                ->loadByCode('customer', 'gender');
        $optionId = null;
        if ($attribute->getId()) {
            $optionId = $attribute->getSource()->getOptionId($value);
        }

        return $optionId;
    }

    /**
     * Import gift message for order or order item
     *
     * @param array $data
     */
    protected function _importGiftMessage(&$data)
    {
        if ($data['gift_message_id']) {
            $giftMessage = array(
                'customer_id' => (int) (isset($data['customer_id']) ? $data['customer_id'] : null),
                'sender' => $data['gift_sender'],
                'recipient' => $data['gift_recipient'],
                'message' => $data['gift_message']
            );
            try {
                $giftMessageId = Mage::getResourceModel('giftmessage/message')->import($giftMessage);
                $data['gift_message_id'] = $giftMessageId;
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }
        unset($data['gift_sender']);
        unset($data['gift_recipient']);
        unset($data['gift_message']);
    }

    /**
     * Import address data to order
     *
     * @param  $orderId
     * @param  $data
     * @param int $customerId
     */
    protected function _importAddress($orderId, $data, $customerId)
    {
        /** @var $addressResource Mage_Sales_Model_Mysql4_Order_Address */
        $addressResource = Mage::getResourceModel('sales/order_address');
        foreach ($data as $address) {
            $address['parent_id'] = $orderId;
            $address['quote_address_id'] = null;
            $address['customer_address_id'] = null;
            $address['customer_id'] = $customerId;
            $addressId = $addressResource->import($address);
            if ($address['address_type'] == 'billing') {
                $this->updateBillingAddressId($orderId, $addressId);
            } else {
                $this->updateShippingAddressId($orderId, $addressId);
            }
        }
    }

    /**
     * Import order products
     *
     * @param  $orderId
     * @param  $data
     * @param string $orderState
     * @param int $storeId
     */
    protected function _importItems($orderId, $data, $orderState, $storeId, $decrementInventory = false)
    {
        /** @var $itemResource Mage_Sales_Model_Mysql4_Order_Item */
        $itemResource = Mage::getResourceModel('sales/order_item');
        foreach ($data as $item) {
            if (!in_array($item['product_type'], $this->_getProductTypes()) && $orderState != 'closed'
                    && $orderState != 'canceled') {
                Mage::throwException('Can not import order because it contains items with unknown product type.');
            }

            $item['order_id'] = $orderId;
            $item['store_id'] = $storeId;
            $item['quote_item_id'] = null;
            $product = $itemResource->getProductIdBySku($item['sku'], $item['product_type']);
            $productModel = Mage::getModel('catalog/product')->load($product['entity_id']);
            if ($product) {
                $item['product_id'] = $product['entity_id'];
                $item['weight'] = $productModel->getWeight() * $item['qty_ordered'];
            } else {
                $item['product_id'] = null;
//                $this->addImportWarning($orderId, sprintf('Product with SKU "%s" was not found', $item['sku']));
                throw new Mage_Api_Exception('SKU does not exist');
            }
            $origItemId = $item['item_id'];
            unset($item['item_id']);
            unset($item['base_weee_tax_applied_row_amnt']);

            // Try to import gift message
            $this->_importGiftMessage($item);
            $itemId = $itemResource->import($item);
            $this->_importedItems[$origItemId] = $itemId;
            if ($decrementInventory)
                $this->_updateInventory($item['product_id'], $item['qty_ordered'], $orderId, $item['sku']);
            if (!empty($item['parent_item_id'])) {
                $this->_itemsWithParent[$itemId] = $item['parent_item_id'];
            }
        }
    }

    /**
     * Restore relations between order items
     */
    protected function _restoreItemsRelations()
    {
        foreach ($this->_itemsWithParent as $itemId => $oldItemId) {
            if (isset($this->_importedItems[$oldItemId])) {
                try {
                    Mage::getResourceModel('sales/order_item')->updateParentRelation($itemId, $this->_importedItems[$oldItemId]);
                } catch (Exception $e) {
                    Mage::logException($e);
                }
            }
        }
    }

    /**
     * Get available product types
     *
     * @return array
     */
    protected function _getProductTypes()
    {
        if (!count($this->_productTypes)) {
            $productTypeCollection = Mage::getModel('catalog/product_type')
                    ->getOptionArray();

            foreach ($productTypeCollection as $id => $type) {
                $this->_productTypes[] = $id;
            }
        }

        return $this->_productTypes;
    }

    /**
     * Import order payments
     *
     * @param  $orderId
     * @param  $data
     */
    protected function _importPayments($orderId, $data)
    {
        /** @var $paymentResource Mage_Sales_Model_Mysql4_Order_Payment */
        $paymentResource = Mage::getResourceModel('sales/order_payment');
        foreach ($data as $payment) {
            $payment['parent_id'] = $orderId;
            $payment['cc_number_enc'] = null;
            $paymentResource->import($payment);
        }
    }

    /**
     * Import order status history
     *
     * @param  $orderId
     * @param  $data
     */
    protected function _importHistory($orderId, $data)
    {
        /** @var $historyResource Mage_Sales_Model_Mysql4_Order_Status_History */
        $historyResource = Mage::getResourceModel('sales/order_status_history');
        foreach ($data as $history) {
            $history['parent_id'] = $orderId;
            unset($history['entity_name']);
            $historyResource->import($history);
        }
    }

    /**
     * Add warning message to imported order
     *
     * @param int $orderId
     * @param string $message
     * @return void
     */
    protected function addImportWarning($orderId, $message)
    {
        $this->_importWarnings[$orderId][] = $message;
    }

    /**
     * Get warnings for imported order
     *
     * @param  $orderId
     * @return array
     */
    public function getImportWarnings($orderId)
    {
        $result = array();
        if (isset($this->_importWarnings[$orderId])) {
            $result = $this->_importWarnings[$orderId];
        }
        return $result;
    }

    /**
     * Subtract quantity ordered for inventory
     * @param type $itemId
     * @param type $qtyOrdered
     * @param string $orderId
     */
    private function _updateInventory($itemId, $qtyOrdered, $orderId, $sku)
    {
        $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($itemId);
        if ($stockItem->verifyStock()) {
            $stockItem->subtractQty($qtyOrdered);
            if ($stockItem->getQty() < 0) {
                $this->addImportWarning($orderId, "Product $sku is backordered");
            }
            $stockItem->save();
        }
        else {
            throw new Exception("Product $sku can't be backordered");
        }
        
    }

}
