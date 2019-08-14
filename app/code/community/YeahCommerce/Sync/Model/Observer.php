<?php

require_once "Mobile_Detect.php";

class YeahCommerce_Sync_Model_Observer {

    private static $_type;

    const YEAH_HOST = 'https://yeahcommerce.com/api/v01/';
    const TYPE_PRODUCT = 'product';
    const TYPE_CATEGORY = 'category';
    const TYPE_SITE = 'site';
    const YEAH_TEMP_DIR = 'yeah_data_dir';
    const DELETED_PRODUCTS_INFO = 'Deleted_Products_Info.gz';

    /**
     * @param $observer
     */
    public function redirectToMobile($observer){
        $do_redirect = Mage::getStoreConfig('yeah_sync_section/yeah_sync_group/redirect_check');
        if ($this->_detectMobile() && $do_redirect){
            $product = $observer->getData('product');
            if (!$product || empty($product)){
                $category = $observer->getData('category');
                if (!$category || empty($category)){
                    if (!isset(self::$_type)){
                        if ($this->_getIsHomePage()){
                            $this->_redirect('');
                        }
                    }
                } else {
                    if (!isset(self::$_type)){
                        $this->_setType(self::TYPE_CATEGORY);
                        $this->_redirectToMobileVersionCategory($category);
                    }
                }
            } else {
                if (!isset(self::$_type)){
                    $this->_setType(self::TYPE_PRODUCT);
                    $this->_redirectToMobileVersionProduct($product);
                }
            }
        }
    }

    /**
     * Saves an ID of deleted product to an archive DELETED_PRODUCTS_INFO
     * @param $observer
     */
    public function saveOnDelete($observer){ 
        try{
            $id = $observer->getData('product')->getId();
            $path = self::_getVarFolder().self::DELETED_PRODUCTS_INFO;
            $f = gzopen($path, 'a');
            gzwrite($f, $id.',');
            gzclose($f);            
        } catch (Exception $e){
            print_r($e->getMessage());
            exit;
        }
        return;
    }

    private static function _getVarFolder(){
        return Mage::getBaseDir() . DS . 'var' . DS . self::YEAH_TEMP_DIR . DS;
    }
    /**
     * @return bool
     */
    private function _getIsHomePage()
    {
        $page = Mage::app()->getFrontController()->getRequest()->getRouteName();
        $homePage = false;

        if($page =='cms'){
            $cmsSingletonIdentifier = Mage::getSingleton('cms/page')->getIdentifier();
            $homeIdentifier = Mage::app()->getStore()->getConfig('web/default/cms_home_page');
            if($cmsSingletonIdentifier === $homeIdentifier){
                $homePage = true;
            }
        }

        return $homePage;
    }

    /**
     * @return bool
     */
    private function _detectMobile(){
        $detect = new Mobile_Detect;
        return $detect->isMobile();
    }

    /**
     * @param $type
     */
    private function _setType($type){
        if (!isset(self::$_type)){
            self::$_type = $type;
        }
    }

    /**
     * @param $product
     */
    private function _redirectToMobileVersionProduct($product)
    {
        $url = $this->_constructUrl(self::TYPE_PRODUCT, $product->getId());
        $app_id = $this->_makeRequest($url);
        if (is_int($app_id)){
            $this->_redirect('#product/'.$app_id);
        }
    }

    /**
     * @param $category
     */
    private function _redirectToMobileVersionCategory($category){
        $url = $this->_constructUrl(self::TYPE_CATEGORY, $category->getId());
        $app_id = $this->_makeRequest($url);
        if (is_int($app_id)){
            if ($this->_hasSubCategories($category)){
                $url = '#category_cat/'.$app_id;
            } else {
                $url = '#category_prod/'.$app_id;
            }
            $this->_redirect($url);
        }
    }

    /**
     * @param $url
     */
    private function _redirect($url){
        $key = Mage::getStoreConfig('yeah_sync_section/yeah_sync_group/secret_key');
        $request = self::YEAH_HOST.$key.'/get_url';
        $domain = $this->_makeRequest($request);
        $targetUrl = $domain.$url;
        Mage::app()->getFrontController()->getResponse()->setRedirect($targetUrl);
    }

    /**
     * Compose a string to make request to mobile API
     * @param $type
     * @param $id
     * @return string
     */
    private function _constructUrl($type, $id){
        $key = Mage::getStoreConfig('yeah_sync_section/yeah_sync_group/secret_key');
        $url = self::YEAH_HOST.$key.'/'.$type.'/get_id/'.$id;
        return $url;
    }

    /**
     * Check if category has subcategories
     * @param $category
     * @return bool
     */
    private function _hasSubCategories($category){
        $cat = Mage::getModel('catalog/category')->load($category->getId());
        $subcats = $cat->getChildren();
        if (empty($subcats)){
            return false;
        } else {
            return true;
        }
    }

    /**
     * Get id of a product or category in mobile version
     * @param $url
     * @return mixed
     */
    private function _makeRequest($url){
        $process = curl_init($url);
        curl_setopt($process, CURLOPT_TIMEOUT, 30);
        curl_setopt($process, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($process, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($process, CURLOPT_SSL_VERIFYHOST, false);
        var_dump($url);
        $return = curl_exec($process);
        $data = json_decode($return);
        var_dump($data);
        curl_close($process);
        if ($data->id){
            return $data->id;
        }elseif($data->url){
            return $data->url;
        }else{
            return NULL;
        }
    }

}