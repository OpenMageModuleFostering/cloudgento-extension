<?php

class CloudConversion_CloudGento_Model_Order_Shipment_Api extends Mage_Sales_Model_Order_Shipment_Api
{

    public function items($filters = null)
    {
        $shipments = array();
        $shipmentCollection = Mage::getResourceModel('sales/order_shipment_collection')
                ->addAttributeToSelect('increment_id')
                ->addAttributeToSelect('created_at')
                ->addAttributeToSelect('total_qty')
                ->addAttributeToSelect('order_id')
                ->joinAttribute('shipping_firstname', 'order_address/firstname', 'shipping_address_id', null, 'left')
                ->joinAttribute('shipping_lastname', 'order_address/lastname', 'shipping_address_id', null, 'left')
                ->joinAttribute('order_increment_id', 'order/increment_id', 'order_id', null, 'left')
                ->joinAttribute('order_created_at', 'order/created_at', 'order_id', null, 'left');
        ;
        /** @var $apiHelper Mage_Api_Helper_Data */
        $apiHelper = Mage::helper('api');
        try {
            $filters = $apiHelper->parseFilters($filters, $this->_attributesMap['shipment']);
            foreach ($filters as $field => $value) {
                $shipmentCollection->addFieldToFilter($field, $value);
            }
        } catch (Mage_Core_Exception $e) {
            $this->_fault('filters_invalid', $e->getMessage());
        }
        foreach ($shipmentCollection as $shipment) {
            $attributes = $this->_getAttributes($shipment, 'shipment');
            $order = $shipment->getOrder();
            $attributes['order_increment_id'] = $order->getIncrementId();
            $attributes['shipping_description'] = $order->getShippingDescription();
            $shipments[] = $attributes;
        }

        return $shipments;
    }

}

