<?php
/**
 * Yeah!Commerce Sync Data helper
 */
class YeahCommerce_Sync_Helper_Data extends Mage_Core_Helper_Data{
    /**
     * Path to store config if front-end output is enabled
     * @var string
     */
    const XML_PATH_SECRET_KEY = 'yeah_sync/view/secret_key';


    /**
     * @param integer|string|Mage_Core_Model_Store $store
     * @return int
     */
    public function getSecretKey($store = null){
        return Mage::getStoreConfig(self::XML_PATH_SECRET_KEY, $store);
    }

}