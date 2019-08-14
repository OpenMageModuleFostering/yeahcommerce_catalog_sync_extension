<?php
class YeahCommerce_Sync_IndexController extends Mage_Core_Controller_Front_Action{
	const YEAH_TEMP_DIR = 'yeah_data_dir';
	const LAST_UPDATE = '14.12.2012';
	const MODULE_TYPE = 'magento';
	const DELETED_PRODUCTS_INFO = 'Deleted_Products_Info.gz';

	/**
	 * Checking KEY
	 * @return Mage_Core_Controller_Front_Action|void
	 */
	public function preDispatch(){
		parent::preDispatch();
		$this->_sessionNamespace = 'frontend';

		$key = Mage::getStoreConfig('yeah_sync_section/yeah_sync_group/secret_key');

		if(isset($_GET['createOrder'])){
			return;
		}

		if(empty($key)){
			header("HTTP/1.0 400 Bad Request");
			die('{error:400}');
		}

		if(Mage::app()->getRequest()->get('key') !== $key){
			header("HTTP/1.0 401 Unauthorized");
			die('{error:401}');
		}
	}

	/**
	 * Default action
	 * Just info about the module
	 */
	public function indexAction(){
		if(isset($_GET['createOrder'])){
			$this->createOrderAction();
			return;
		}elseif(isset($_GET['products'])){
			$this->productsAction();
			return;
		}elseif(isset($_GET['categories'])){
			$this->categoriesAction();
		}elseif(isset($_GET['order'])){
            $this->orderAction();
			return;
		}else{
			$response = array(
				'module' => self::MODULE_TYPE,
				'ver' => Mage::getConfig()->getModuleConfig('YeahCommerce_Sync')->version->__toString(),
				'last_update' => self::LAST_UPDATE,
				'changes' => false,
			);
			echo json_encode($response);
		}
	}

	public function orderAction(){
		$order = $_POST['order'];
		echo Mage::getUrl('sync') .'?createOrder&orderData='.urlencode($order);
	}

	/**
	 * Gets a product associated with given configurable product by given params
	 * @param integer $id
	 * @param array $params
	 */
	private function _getAssociatedProductByAttributes($id,$params){
		$productModel = Mage::getModel('catalog/product');
		$product = $productModel->load($id);
        $instance = $product->getTypeInstance(true);
        $associated_products = $instance->getUsedProducts(null, $product); // Получаем продукты
        foreach($params as $key => $value){
			$attr = $productModel->getResource()->getAttribute($key);
			if ($attr->usesSource()) {
				$params_ids[$key] = $attr->getSource()->getOptionId($value);
			}
		}
		foreach($associated_products as $prod){
			$flag = true;
			foreach($params_ids as $key => $value){
				if ($prod->getData()[$key] != $params_ids[$key]){
					$flag = false;
				}
			}
			if ($flag == true){
				$result = $prod;
			}
		}
		return $result;
	}

	public function createOrderAction(){
		$order = json_decode($_GET['orderData']);
		try{
			$this->_createOrder($order);
		}catch (Exception $e){
			print_r($e->getMessage());
		}

		$this->_redirect('checkout/cart');
	}

	/**
	 * Adds order into guest user cart and returns SID=session pair for login into the cart
	 * @param array $products
	 * @return string "SID=sessionid"
	 */
	private function _createOrder($products){
		$cart = Mage::getSingleton('checkout/cart', array('name' => 'frontend'));
		$cart->init();

		foreach($products as $one){
			$productModel = Mage::getModel('catalog/product');
			$id = $productModel->getIdBySku($one->sku);
			$product = $productModel->load($id);
			$qty = Mage::getModel('cataloginventory/stock_item')->loadByProduct($id)->getQty();
			$options = array();
			$type = $product->getTypeId();
			var_dump($type);
            switch($type){
                case Mage_Catalog_Model_Product_Type::TYPE_SIMPLE: // Simple product (with attributes)
                	echo "SIMPLE!!!";
					if($one->options){
						foreach ($one->options as $opt => $value) {
							$option_id = $this->_getCustomOptionId($product, $opt);
							$option_value_id = $this->_getCustomOptionTypeId($product, $option_id, $value);
							$options[$option_id] = $option_value_id;
						}
					}
					break;
				case Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE:
					echo "CONF!!!";
					$conf = $this->_getAssociatedProductByAttributes($id,$one->options);
					$id = $conf->getEntityId();
					$product = $conf;
					break;
			}
			try{
				$params = new Varien_Object(array(
	                 'product' => $id,
	                 'qty' => min($one->count, $qty),
	                 'options' => $options
	            ));
				$cart->addProduct($product, $params);
			}catch (Exception $e){
				print_r($e->getMessage());
				exit;
			}
		}
		$cart->save();

		/** @var $session Mage_Checkout_Model_Session */
		$session = Mage::getSingleton('core/session', array('name' => 'frontend'));
		$session->setCartWasUpdated(true);
		return $session->getSessionIdQueryParam() .'='. $session->getSessionId();
	}

	/**
	 * Gets option_id value for given product using option title
	 * @param $product
	 * @param string $title
	 * @return integer
	 */
	private function _getCustomOptionId($product, $title){
		foreach ($product->getOptions() as $option) {
			if ($option["title"] == $title){
				return $option["option_id"];
			}
		}
		return 0;
	}

	/**
	 * Gets option_type_id value for given option and product
	 * @param $product
	 * @param $option_id
	 * @param string $title
	 * @return integer
	 */
	private function _getCustomOptionTypeId($product, $option_id, $title){
		foreach ($product->getOptions() as $option) {
			if ($option["option_id"] == $option_id){
				foreach ($option->getValues() as $value) {
					if ($value["title"] == $title){
						return $value["option_type_id"];
					}
				}
			}
		}
		return 0;
	}
    /**
     * Gets bundle product
     * @param $current_product
     * @return array
     */
    private function _getBundleProduct($current_product){
        $components = array();
        $bundle_items = Mage::getBlockSingleton('bundle/catalog_product_view_type_bundle')->setProduct($current_product)->getOptions();
        foreach($bundle_items as $bundle){
            $products = array();
            $options = $bundle->getData();
            $items = $options['selections'];

            foreach($items as $i){
                $product = $i->getData();
                $tmp_product['id'] = $product['entity_id']; // Id продукта
                $tmp_product['default_qty'] = $product['selection_qty']; // Дефолтное количество
                $tmp_product['user_qty'] = (bool)$product['selection_can_change_qty']; // Может ли пользователь менять количество
                $tmp_product['default'] = (bool)$product['is_default'];
                $products[] = $tmp_product;
            }

            $components[] = array(
                'title' => $options['title'],
                'required' => (bool)$options['required'],
                'type' => $options['type'], // select, radio, checkbox, multi
                'products' => $products
            );
        }

        return $components;
    }

    /**
     * Get configurable product with its options
     * @param $current_product
     * @return array
     */
    private function _getConfigurableProduct($current_product){
        $components = array();
        $instance = $current_product->getTypeInstance(true);
        $associated_products = $instance->getUsedProducts(null, $current_product); // Retrieves associated products
        $product_attributes = $instance->getConfigurableAttributesAsArray($current_product); // Retrieves products attributes

        $attributes = array();
        $attributes_name = array();
        $options = array();
        foreach($product_attributes as $attribute){
        	$i = 0;
            foreach($attribute['values'] as $v){
                // Creating an array: array(attribute title => array(value id => array())
                $options[ $attribute['attribute_code'] ][$i++] = array(
                    'value' => $v['label'], // attribute label
                    'pricing_value' => is_null($v['pricing_value']) ? "0" : $v['pricing_value'], // price
                    'is_percent' => (bool)$v['is_percent'] // True - price is percentage, false - fixed
                );
            }
        }
        return $options;
    }

    /**
     * Gets Custom options
     * @param $current_product
     * @return array
     */
    private function _getOptions($current_product){
        $options = array();

        return $options;
    }

	/**
	 * Retrievies all (or recently updated) products, 
	 * creates gzip-archive with json-encoded info and returns the link
	 */
	public function productsAction(){
		$collection = Mage::getModel('catalog/product')->getCollection() /** @var $collection */
	      ->joinField('stock_status','cataloginventory/stock_status','stock_status',
			          'product_id=entity_id', array(
			          'stock_status' => Mage_CatalogInventory_Model_Stock_Status::STATUS_IN_STOCK,
	                  'website_id' => Mage::app()->getWebsite()->getWebsiteId(),
	        ))
			->addAttributeToFilter('status', 1)
			->addAttributeToSelect('name')
			->addAttributeToSelect('description')
			->addAttributeToSelect('price')
			->addAttributeToSelect('image')
			->addAttributeToSelect('category')
			->addAttributeToSelect('category_ids')
			->addAttributeToSelect('url_path');
		if(!empty($_REQUEST['from_ts'])){
			$from_ts = (int)$_REQUEST['from_ts'];
			if($from_ts > mktime(0, 0, 0, 0, 0, 2000) && $from_ts < time()){
				$collection = $collection->addAttributeToFilter('updated_at', array('gt' => date('Y.m.d H:i:s', $from_ts)));
			}
		}
		if(count($collection)){
			addMediaGalleryAttributeToCollection($collection);
		}
		$products = array();
		foreach($collection as $one){ /** @var $one Mage_Catalog_Model_Product */
			$categories = $one->getCategoryIds();
			if ($categories){
				//making images array
				$images = array();
				if($imgCollection = $one->getMediaGalleryImages()){
					$baseImage = $one->image;
					foreach($imgCollection as $img){
						if($img->file == $baseImage){
							$images[] = array('path' => $img->url, 'status' => 1);
						}else{
							$images[] = array('path' => $img->url, 'status' => 0);
						}
					}
				}
				//info about the product
	            $options = array();

	            $type = $one->getTypeId();
	            switch($type){
	                case Mage_Catalog_Model_Product_Type::TYPE_BUNDLE: // Product type is Bundle
	                    $options = $this->_getBundleProduct($one);
	                    break;
	                case Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE: // Product type is Configurable
	                    $options = $this->_getConfigurableProduct($one);
	                    break;
	            }

	            $products[$one->getEntityId()] = array(
	                'name' => $one->name,
	                'sku' => $one->getSku(),
	                'desc' => $one->description,
	                'images' => $images,
	                'price' => $one->getPrice(), //may be high load caused by "calculatePrice"
	                'category' => $categories,
	                'type' => $type, // simple, bundle, configurable
	                'options' => $options,
	                'components' => array()
	            );
			}
			
		}

		// Gathering option values
		$optionId2values = array();
		$collection = Mage::getResourceModel('catalog/product_option_value_collection')->getValues(0);
		foreach($collection as $one){
            $data = $one->getData();
			$optionId2values[ $data['option_id'] ][$data['option_type_id']] = array(
                'value' => $data['title'],
                'pricing_value' => $data['price'],
                'is_percent' => ($data['price_type'] == 'fixed') ? false : true, // fixed, percent
                'sku' => $data['sku'],
                'order' => $data['sort_order']
            );
		}

		//Gathering custom options
		$collection = Mage::getModel('catalog/product_option')
			->getCollection()
			->addTitleToResult(0)
			->addPriceToResult(0);
		foreach($collection as $one){
            $data = $one->getData();
			$oid = $data['option_id'];
			$oname = $data['title'];
			$pid = $data['product_id'];
			if(isset($products[$pid])){
				$params = array(					
					'option_id' => $oid,
                    'type' => $data['type'],
                    'required' => (bool)$data['is_require'],
                    'order' => $data['sort_order']
                );
				$products[$pid]['options'][$oname]['_params'] = $params;
				if (!empty($optionId2values[$oid])){
					$products[$pid]['options'][$oname] += array_values($optionId2values[$oid]);
				}				
			}
		}

		if ($data = self::_openArchive(self::DELETED_PRODUCTS_INFO)){
			$deleted = preg_split('/,/', $data);
			foreach ($deleted as $deleted_id) {
				$products[$deleted_id]['type'] = "deleted";
			}
			unset($data);
			unset($deleted);
		}
		
		unset($collection);
		unset($optionId2values);
		//dumping all
		$archive =  self::_createArchive('products', json_encode($products));
		$response = array('link' => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB) . 'var/'.self::YEAH_TEMP_DIR.'/'.$archive);
        echo json_encode($response);
	}

	/**
	 * Retrievies all the categories, creates gzip-archive and returns the link
	 */
	public function categoriesAction(){
		$collection = Mage::getModel('catalog/category')->getCollection()
			->addAttributeToSelect('name')
			->addAttributeToSelect('image')
			->addAttributeToSelect('url_path')
			->addAttributeToSelect('is_active');

		if(!empty($_REQUEST['from_ts'])){
			$from_ts = (int)$_REQUEST['from_ts'];
			if($from_ts > mktime(0, 0, 0, 0, 0, 2000) && $from_ts < time()){
				$collection = $collection->addAttributeToFilter('updated_at', array('gt' => date('Y.m.d H:i:s', $from_ts)));
			}
		}

		$cats = array();
		$root_cat_id = Mage::app()->getStore()->getRootCategoryId();
		$root_parent = null;
		foreach($collection as $cat){
			$cat_id = $cat->getEntityId();
			//we memorize parent of root category in order to delete it in future
			//we don't write the root category also
			if($cat_id == $root_cat_id){
                $root_parent = $cat->parent_id;
                continue;
			}
			$parent_id = $cat->parent_id == $root_cat_id ? 0 : $cat->parent_id;
			$cats[$cat_id] = array(
				'path' => '/'.$cat->url_path,
				'name' => $cat->name,
				'pid' => $parent_id,
				'img' => $cat->getImageUrl(),
				'isActive' => (bool)$cat->is_active,
			);
		}
		//deleting parent of root category
		if(!is_null($root_parent)){
			unset($cats[$root_parent]);
		}

		$archive =  self::_createArchive('category', json_encode($cats));
        $response = array('link' => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB) . 'var/'.self::YEAH_TEMP_DIR.'/'.$archive);
		echo json_encode($response);
	}

	/**
	 * @param string $type
	 * @param string $data
	 * @throws Exception
	 * @return string File name
	 */
	private static function _createArchive($type, $data){
		if($type != 'category' && $type != 'products'){
			throw new Exception('Wrong type: '.$type);
		}

		self::_cleanOldFiles();

		$fname = $type."_".date('Y_m_d_H_i_s') . '.gz';
		$path = self::_getVarFolder() . $fname;
		$f = gzopen($path, 'w9');
		gzwrite($f, $data);
		gzclose($f);
		return $fname;
	}

	/**
	 * @param string $fname
	 * @return string File content
	 */
	private static function _openArchive($fname){
		$path = self::_getVarFolder() . $fname;
		$f = gzopen($path,'r');
		$data = gzread($f, 10000);
		gzclose($f);
		return substr($data, 0, -1);
	}

	/**
	 * Clean old files
	 */
	private function _cleanOldFiles(){
		$path = self::_getVarFolder();
		$files = scandir($path);
		$prods = array_filter($files, function($v){ return preg_match('/^products_\d\d\d\d(_\d\d){5}\.gz$/', $v); });
		$cats = array_filter($files, function($v){ return preg_match('/^category_\d\d\d\d(_\d\d){5}\.gz$/', $v); });
		unset($files);
		sort($prods);
		sort($cats);
		while(count($prods) > 2){
			$file = array_shift($prods);
			unlink($path.$file);
		}
		while(count($cats) > 2){
			$file = array_shift($cats);
			unlink($path.$file);
		}
		unlink($path.self::DELETED_PRODUCTS_INFO);
	}

	/**
	 * Checks that data folder in "var" is created and "htaccess" is set up
	 * @return string
	 */
	private static function _getVarFolder(){
		$var_dir = Mage::getBaseDir() . DS . 'var' . DS . self::YEAH_TEMP_DIR . DS;
		if(!file_exists($var_dir)){
			mkdir($var_dir, 0777);
			//create htaccess file
			$htaccess = $var_dir . '.htaccess';
			$hf = fopen($htaccess, 'w');
			fwrite($hf, "Order allow,deny\nAllow from all");
			fclose($hf);
		}
		return $var_dir;
	}
}

/**
 * A really magic function. But there is no better way to load gallery images
 * along with product collection
 * @link http://www.magentocommerce.com/boards/viewthread/17414/#t141830
 * @param $_productCollection
 * @return
 */
function addMediaGalleryAttributeToCollection($_productCollection) {
	$_mediaGalleryAttributeId = Mage::getSingleton('eav/config')->getAttribute('catalog_product', 'media_gallery')->getAttributeId();
	$_read = Mage::getSingleton('core/resource')->getConnection('catalog_read');

	$_mediaGalleryData = $_read->fetchAll('
        SELECT
            main.entity_id, `main`.`value_id`, `main`.`value` AS `file`,
            `value`.`label`, `value`.`position`, `value`.`disabled`, `default_value`.`label` AS `label_default`,
            `default_value`.`position` AS `position_default`,
            `default_value`.`disabled` AS `disabled_default`
        FROM `catalog_product_entity_media_gallery` AS `main`
            LEFT JOIN `catalog_product_entity_media_gallery_value` AS `value`
                ON main.value_id=value.value_id AND value.store_id=' . Mage::app()->getStore()->getId() . '
            LEFT JOIN `catalog_product_entity_media_gallery_value` AS `default_value`
                ON main.value_id=default_value.value_id AND default_value.store_id=0
        WHERE (
            main.attribute_id = ' . $_read->quote($_mediaGalleryAttributeId) . ')
            AND (main.entity_id IN (' . $_read->quote($_productCollection->getAllIds()) . '))
        ORDER BY IF(value.position IS NULL, default_value.position, value.position) ASC
    ');


	$_mediaGalleryByProductId = array();
	foreach ($_mediaGalleryData as $_galleryImage) {
		$k = $_galleryImage['entity_id'];
		unset($_galleryImage['entity_id']);
		if (!isset($_mediaGalleryByProductId[$k])) {
			$_mediaGalleryByProductId[$k] = array();
		}
		$_mediaGalleryByProductId[$k][] = $_galleryImage;
	}
	unset($_mediaGalleryData);
	foreach ($_productCollection as &$_product) {
		$_productId = $_product->getData('entity_id');
		if (isset($_mediaGalleryByProductId[$_productId])) {
			$_product->setData('media_gallery', array('images' => $_mediaGalleryByProductId[$_productId]));
		}
	}
	unset($_mediaGalleryByProductId);

	return $_productCollection;
}
?>