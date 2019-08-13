<?php

/**
 * Extension of Magento Soap API V1
 *
 * @package    CloudConversion
 * @author     Andrea De Pirro <andrea.depirro@yameveo.com>
 * @author     Johann Reinke @johannreinke
 * @see        Mage_Catalog_Model_Product_Api
 */
class CloudConversion_CloudGento_Model_Catalog_Product_Api extends Mage_Catalog_Model_Product_Api
{

    public function create($type, $set, $sku, $productData, $store = null)
    {
        // Allow attribute set name instead of id
        if (is_string($set) && !is_numeric($set)) {
            $set = Mage::helper('cloudconversion_cloudgento')->getAttributeSetIdByName($set);
        }

        return parent::create($type, $set, $sku, $productData, $store);
    }

    protected function _prepareDataForSave($product, $productData)
    {
        /* @var $product Mage_Catalog_Model_Product */

        if (isset($productData['categories'])) {
            $categoryIds = Mage::helper('cloudconversion_cloudgento/catalog_product')
                    ->getCategoryIdsByNames((array) $productData['categories']);
            if (!empty($categoryIds)) {
                $productData['categories'] = array_unique($categoryIds);
            }
        }

        if (isset($productData['website_ids'])) {
            $websiteIds = $productData['website_ids'];
            foreach ($websiteIds as $i => $websiteId) {
                if (!is_numeric($websiteId)) {
                    $website = Mage::app()->getWebsite($websiteId);
                    if ($website->getId()) {
                        $websiteIds[$i] = $website->getId();
                    }
                }
            }
            $product->setWebsiteIds($websiteIds);
            unset($productData['website_ids']);
        }

        foreach ($productData as $code => $value) {
            $productData[$code] = Mage::helper('cloudconversion_cloudgento/catalog_product')
                    ->getOptionKeyByLabel($code, $value);
        }

        parent::_prepareDataForSave($product, $productData);

        if (isset($productData['associated_skus'])) {
            $simpleSkus = $productData['associated_skus'];
            $priceChanges = isset($productData['price_changes']) ? $productData['price_changes'] : array();
            Mage::helper('cloudconversion_cloudgento/catalog_product')->associateProducts($product, $simpleSkus, $priceChanges);
        }
    }

    /**
     * Retrieve product info. 
     * Optional attributes configurable_attributes_data and configurable_products_data
     * show info on children products and confiurable options
     *  
     * @param int|string $productId
     * @param string|int $store
     * @param stdClass $attributes
     * @param string $identifierType (sku or null)
     * @return array
     */
    public function info($productId, $store = null, $attributes = null, $identifierType = null)
    {
        $product = $this->_getProduct($productId, $store, $identifierType);
        $all_attributes = in_array('*', $attributes);

        $result = array(// Basic product data
            'product_id' => $product->getId(),
            'sku' => $product->getSku(),
            'set' => $product->getAttributeSetId(),
            'type' => $product->getTypeId(),
            'categories' => $product->getCategoryIds(),
            'websites' => $product->getWebsiteIds()
        );

        foreach ($product->getTypeInstance(true)->getEditableAttributes($product) as $attribute) {
            if ($this->_isAllowedAttribute($attribute, $attributes) || $all_attributes) {
                $result[$attribute->getAttributeCode()] = $product->getData(
                        $attribute->getAttributeCode());
            }
        }
        return $this->infoResult($result, $product, $attributes, $store, $all_attributes);
    }

    public function infoResult($result, $product, $attributes, $store, $all_attributes)
    {
        $productId = $product->getId();
        if (in_array('url_complete', $attributes) || $all_attributes) {
            $result['url_complete'] = $product->setStoreId($store)->getProductUrl();
        }
        if (in_array('stock_data', $attributes) || $all_attributes) {
            $result['stock_data'] = Mage::getSingleton('Mage_CatalogInventory_Model_Stock_Item_Api')->items($productId);
        }
        if (in_array('images', $attributes) || $all_attributes) {
            $result['images'] = Mage::getSingleton('Mage_Catalog_Model_Product_Attribute_Media_Api')->items($productId, $store);
        }
        if (!$product->isSuper() && (in_array('parent_sku', $attributes) || $all_attributes)) {
            $parentIds = Mage::getModel('catalog/product_type_configurable')->getParentIdsByChild($product->getId());
            if (!$parentIds)
                $parentIds = Mage::getModel('catalog/product_type_grouped')->getParentIdsByChild($product->getId());
            if (isset($parentIds[0])) {
                $parent = Mage::getModel('catalog/product')->load($parentIds[0]);
                $result['parent_sku'] = $parent->getSku();
            }
        } elseif ($product->isConfigurable()) {
            $attributesData = $product->getTypeInstance()->getConfigurableAttributesAsArray();
            // configurable_options
            if (in_array('configurable_attributes_data', $attributes) || $all_attributes) {
                $options = array();
                $k = 0;
                foreach ($attributesData as $attribute) {
                    $options[$k]['code'] = $attribute['attribute_code'];
                    foreach ($attribute['values'] as $value) {
                        $value['attribute_code'] = $attribute['attribute_code'];
                        $options[$k]['options'][] = $value;
                    }
                    $k++;
                }
                $result['configurable_attributes_data'] = $options;
            }
            // children
            if (in_array('configurable_products_data', $attributes) || $all_attributes) {
                // @todo use $childProducts = $product->getTypeInstance()->getUsedProducts();
                $childProducts = Mage::getModel('catalog/product_type_configurable')
                        ->getUsedProducts(null, $product);
                $skus = array();
                $i = 0;
                foreach ($childProducts as $childProduct) {
                    $skus[$i]['sku'] = $childProduct->getSku();
                    $j = 0;
                    foreach ($attributesData as $attribute) {
                        $skus[$i]['options'][$j]['label'] = $attribute['label'];
                        $skus[$i]['options'][$j]['attribute_code'] = $attribute['attribute_code'];
                        $skus[$i]['options'][$j]['value_index'] = $childProduct[$attribute['attribute_code']];
                        $j++;
                    }
                    $i++;
                }
                $result['configurable_products_data'] = $skus;
            }
        }
        return $result;
    }

    /**

     * Retrieve list of products with basic info (id, sku, type, set, name)

     *

     * @param null|object|array $filters
     * @param string|int $store
     * @param string productUpdatedAt timestamp to list only products with updated qty

     * @return array

     */
    public function items($filters = null, $store = null)
    {

        $collection = Mage::getModel('catalog/product')->getCollection()
                ->addStoreFilter($this->_getStoreId($store))
                ->addAttributeToSelect('name')
                ->addAttributeToSelect('price');

        /** @var $apiHelper Mage_Api_Helper_Data */
        $apiHelper = Mage::helper('api');
        $filters = $apiHelper->parseFilters($filters, $this->_filtersMap);
        if (isset($filters['updated_at'])) {
            $methods = array(
                'lt' => '<',
                'gt' => '>',
                'eq' => '=',
                'lteq' => '<=',
                'gteq' => '>=',
                'neq' => '<>'
            );
            // Find all the products with qty modified after productUpdatedAt OR changed
            $updatedAt = $filters['updated_at'];
            $condition =  array_keys($updatedAt);
            $value =  array_values($updatedAt);
            $order_item_table = Mage::getSingleton('core/resource')->getTableName('sales/order_item');
            $collection
                    ->getSelect()
                    ->joinLeft(
                            array('order_item' => $order_item_table), 'order_item.product_id = entity_id', array('item_updated_at' => 'order_item.updated_at')
                    )
                    ->where("order_item.updated_at {$methods[$condition[0]]} '{$value[0]}' OR e.updated_at {$methods[$condition[0]]} '{$value[0]}'")
                    ->group('sku')
            ;
            unset($filters['updated_at']);
        }
        try {
            foreach ($filters as $field => $value) {
                $collection->addFieldToFilter($field, $value);
            }
        } catch (Mage_Core_Exception $e) {

            $this->_fault('filters_invalid', $e->getMessage());
        }

        $result = array();

        foreach ($collection as $product) {
            $stock_item = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
            $qty = $stock_item->getQty();
            $details = array(
                'product_id' => $product->getId(),
                'sku' => $product->getSku(),
                'name' => $product->getName(),
                'set' => $product->getAttributeSetId(),
                'type' => $product->getTypeId(),
                'category_ids' => $product->getCategoryIds(),
                'website_ids' => $product->getWebsiteIds(),
                'available_quantity' => $qty,
                'price' => $product->getPrice()
            );

            $result[] = $details;
        }

        return $result;
    }

}