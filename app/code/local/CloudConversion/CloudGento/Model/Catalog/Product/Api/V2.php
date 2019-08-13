<?php

/**
 * Extension of Magento Soap API V2
 *
 * @package    CloudConversion
 * @author     Andrea De Pirro <andrea.depirro@yameveo.com>
 * @author     Johann Reinke @johannreinke
 * @see        Mage_Catalog_Model_Product_Api_V2
 */
class CloudConversion_CloudGento_Model_Catalog_Product_Api_V2 extends Mage_Catalog_Model_Product_Api_V2
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

        if (property_exists($productData, 'categories')) {
            $categoryIds = Mage::helper('cloudconversion_cloudgento/catalog_product')
                    ->getCategoryIdsByNames((array) $productData->categories);
            if (!empty($categoryIds)) {
                $productData->categories = array_unique($categoryIds);
            }
        }

        if (property_exists($productData, 'additional_attributes')) {
            $singleDataExists = property_exists((object) $productData->additional_attributes, 'single_data');
            $multiDataExists = property_exists((object) $productData->additional_attributes, 'multi_data');
            if ($singleDataExists || $multiDataExists) {
                if ($singleDataExists) {
                    foreach ($productData->additional_attributes->single_data as $_attribute) {
                        $_attrCode = $_attribute->key;
                        $productData->$_attrCode = Mage::helper('cloudconversion_cloudgento/catalog_product')
                                ->getOptionKeyByLabel($_attrCode, $_attribute->value);
                    }
                }
                if ($multiDataExists) {
                    foreach ($productData->additional_attributes->multi_data as $_attribute) {
                        $_attrCode = $_attribute->key;
                        $productData->$_attrCode = Mage::helper('cloudconversion_cloudgento/catalog_product')
                                ->getOptionKeyByLabel($_attrCode, $_attribute->value);
                    }
                }
            } else {
                foreach ($productData->additional_attributes as $_attrCode => $_value) {
                    $productData->$_attrCode = Mage::helper('cloudconversion_cloudgento/catalog_product')
                            ->getOptionKeyByLabel($_attrCode, $_value);
                }
            }
            unset($productData->additional_attributes);
        }

        if (property_exists($productData, 'website_ids')) {
            $websiteIds = (array) $productData->website_ids;
            foreach ($websiteIds as $i => $websiteId) {
                if (!is_numeric($websiteId)) {
                    $website = Mage::app()->getWebsite($websiteId);
                    if ($website->getId()) {
                        $websiteIds[$i] = $website->getId();
                    }
                }
            }
            $product->setWebsiteIds($websiteIds);
            unset($productData->website_ids);
        }

        parent::_prepareDataForSave($product, $productData);

        if (property_exists($productData, 'associated_skus')) {
            $simpleSkus = (array) $productData->associated_skus;
            $priceChanges = array();
            if (property_exists($productData, 'price_changes')) {
                if (key($productData->price_changes) === 0) {
                    $priceChanges = $productData->price_changes[0];
                } else {
                    $priceChanges = $productData->price_changes;
                }
            }
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
        $all_attributes = in_array('*', $attributes->attributes);
        $result = array(// Basic product data
            'product_id' => $product->getId(),
            'sku' => $product->getSku(),
            'set' => $product->getAttributeSetId(),
            'type' => $product->getTypeId(),
            'categories' => $product->getCategoryIds(),
            'websites' => $product->getWebsiteIds()
        );

        $allAttributes = array();
        if (!empty($attributes->attributes)) {
            $allAttributes = array_merge($allAttributes, $attributes->attributes);
        } else {
            foreach ($product->getTypeInstance(true)->getEditableAttributes($product) as $attribute) {
                if ($this->_isAllowedAttribute($attribute, $attributes) || $all_attributes) {
                    $allAttributes[] = $attribute->getAttributeCode();
                }
            }
        }

        $_additionalAttributeCodes = array();
        if (!empty($attributes->additional_attributes)) {
            /* @var $_attributeCode string */
            foreach ($attributes->additional_attributes as $_attributeCode) {
                $allAttributes[] = $_attributeCode;
                $_additionalAttributeCodes[] = $_attributeCode;
            }
        }

        $_additionalAttribute = 0;
        foreach ($product->getTypeInstance(true)->getEditableAttributes($product) as $attribute) {
            if ($this->_isAllowedAttribute($attribute, $allAttributes) || $all_attributes) {
                if (in_array($attribute->getAttributeCode(), $_additionalAttributeCodes) || $all_attributes) {
                    $result['additional_attributes'][$_additionalAttribute]['key'] = $attribute->getAttributeCode();
                    $result['additional_attributes'][$_additionalAttribute]['value'] = $product
                            ->getData($attribute->getAttributeCode());
                    $_additionalAttribute++;
                } else {
                    $result[$attribute->getAttributeCode()] = $product->getData($attribute->getAttributeCode());
                }
            }
        }
        $standard_attributes = $attributes->attributes;
        return Mage::getSingleton('CloudConversion_CloudGento_Model_Catalog_Product_Api')->infoResult($result, $product, $standard_attributes, $store, $all_attributes);
    }

    public function items($filters = null, $store = null)
    {
        return Mage::getSingleton('CloudConversion_CloudGento_Model_Catalog_Product_Api')->items($filters, $store);
    }

}
