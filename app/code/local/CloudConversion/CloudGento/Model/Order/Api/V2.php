<?php

class CloudConversion_CloudGento_Model_Order_Api_V2 extends Mage_Sales_Model_Order_Api_V2
{

    /** @var array $_ordersImported */
    protected $_ordersImported = array();

    /** @var array $_ordersWithChild orders that have child relations */
    protected $_ordersWithChild = array();

    /** @var array $_ordersWithParent orders that have parent relation */
    protected $_ordersWithParent = array();

    /** @var $_orderResource Mage_Sales_Model_Mysql4_Order */
    protected $_orderResource;

    /** @var array $_fieldsMap */
    protected $_fieldsMap = array();

    /**
     * Import orders
     *
     * @param array $orders sales orders
     * @param bool $decrementInventory
     * @return bool
     */
    public function import($orders, $decrementInventory = false)
    {
        Mage::helper('api')->toArray($orders);
        $this->_fieldsMap = array(
                //'forced_shipment_with_invoice'  => 'forced_do_shipment_with_invoice',
                //'payment_auth_expiration'       => 'payment_authorization_expiration',
                //'base_shipping_hidden_tax_amnt' => 'base_shipping_hidden_tax_amount'
        );

        if (is_array($orders) && count($orders)) {
            $response = array('status' => 'Success');
            $this->_orderResource = Mage::getResourceModel('sales/order');

            $fails = 0;
            $warnings = 0;
            foreach ($orders as $order) {
                $this->_mapFieldNames($order);
                try {
                    $origOrderId = $order['order_id'];
                    if (isset($order['increment_id'])) {
                        $origIncrementId = $order['increment_id'];
                        if ($this->_orderResource->checkIncrementIdExists($order['increment_id'])) {
                            Mage::throwException(sprintf('Order #%s already imported', $order['increment_id']));
                        }
                    } else {
                        $origIncrementId = '';
                        $order['increment_id'] = Mage::getSingleton('eav/config')
                                ->getEntityType('order')
                                ->fetchNewIncrementId($order['store_id']);
                    }
                    try {
                        $orderId = $this->_orderResource->importOrder($order, $decrementInventory);
                    } catch (Exception $e) {
                        Mage::logException($e);
                        $this->_fault($e->getMessage());
                    }
                    $this->_ordersImported[$origOrderId] = array(
                        'order_id' => $orderId,
                        'original_increment_id' => $origIncrementId,
                        'imported_increment_id' => $order['increment_id'],
                        'warnings' => $this->_orderResource->getImportWarnings($orderId)
                    );
                    $warnings += count($this->_orderResource->getImportWarnings($orderId));

                    if (!empty($order['relation_child_id'])) {
                        $this->_ordersWithChild[$orderId] = $order['relation_child_id'];
                    }
                    if (!empty($order['relation_parent_id'])) {
                        $this->_ordersWithParent[$orderId] = $order['relation_parent_id'];
                    }
                    Mage::dispatchEvent('cloudconversion_order_import', array('order_id' => $orderId));
                } catch (Exception $e) {
                    $this->_ordersImported[$origOrderId] = array(
                        'original_increment_id' => $origIncrementId,
                        'imported_increment_id' => '',
                        'error' => $e->getMessage()
                    );
                    $fails++;
                }
            }

            if ($fails > 0) {
                $response['status'] = 'Fail';
                if ($fails < count($orders)) {
                    $response['status'] = 'Partial fail';
                }
            } else if ($warnings > 0) {
                $response['status'] = 'Partial fail';
            }

            // Restore parent/child relations and populate aggregated grid table
            try {
                $this->_restoreRelations();

                $importedOrderIds = array();
                foreach ($this->_ordersImported as $orderData) {
                    if (isset($orderData['order_id'])) {
                        $importedOrderIds[] = $orderData['order_id'];
                    }
                }
                $this->_orderResource->updateGridRecords($importedOrderIds);
            } catch (Exception $e) {
                $response['status'] = 'Partial Fail';
            }

            $response['result'] = $this->_ordersImported;
            return $response;
        } else {
            $this->_fault('data_invalid');
        }
    }

    /**
     * Map field names from newest database structure (Magento CE) to their older aliases (Magento GO)
     *
     * @param array $data
     * @return void
     */
    protected function _mapFieldNames(&$data)
    {
        foreach ($this->_fieldsMap as $fieldToMap => $alias) {
            $data[$alias] = $data[$fieldToMap];
            unset($data[$fieldToMap]);
        }
    }

    /**
     * Restore relations between orders
     */
    protected function _restoreRelations()
    {
        foreach ($this->_ordersWithChild as $orderId => $oldChildOrderId) {
            if (isset($this->_ordersImported[$oldChildOrderId])
                    && !empty($this->_ordersImported[$oldChildOrderId]['imported_increment_id'])) {
                try {
                    $this->_orderResource->updateChildRelation($orderId, $this->_ordersImported[$oldChildOrderId]['order_id'], $this->_ordersImported[$oldChildOrderId]['imported_increment_id']);
                } catch (Exception $e) {
                    $this->_ordersImported[$oldChildOrderId]['warnings'][] = 'Could not restore order child relation';
                    throw $e;
                }
            }
        }
        foreach ($this->_ordersWithParent as $orderId => $oldParentOrderId) {
            if (isset($this->_ordersImported[$oldParentOrderId])
                    && !empty($this->_ordersImported[$oldParentOrderId]['imported_increment_id'])) {
                try {
                    $this->_orderResource->updateParentRelation($orderId, $this->_ordersImported[$oldParentOrderId]['order_id'], $this->_ordersImported[$oldParentOrderId]['imported_increment_id']);
                } catch (Exception $e) {
                    $this->_ordersImported[$oldParentOrderId]['warnings'][] = 'Could not restore order parent relation';
                    throw $e;
                }
            }
        }
    }

}
