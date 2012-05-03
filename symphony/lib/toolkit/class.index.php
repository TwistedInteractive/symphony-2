<?php
/**
 * Created by JetBrains PhpStorm.
 * User: Giel
 * Date: 29-03-12
 * Time: 22:39
 * File: class.managerlookup.php
 */
 
class Index
{
	const INDEX_PAGES 		= 'pages';
	const INDEX_SECTIONS 	= 'sections';
	const INDEX_FIELDS 		= 'fields';

	// Instance is used for singleton:
	private static $_instances;

	// Index is used for index management:
	/* @var $_index SimpleXMLElement */
	private $_index;

	// Keep track of the type
	private $_type;

	// The path to the XML-files (workspace/pages and workspace/sections)
	private $_path;

	// The element name to use as root-tag
	private $_element_name;

	// Reference the cachable:
	private $_cache;

	// If the index is dirty (difference between XML files and cached index)
	private $_dirty;

	/**
	 * Get the index
	 *
	 * @param $type
	 *  The type of the index
	 * @return Index
	 */
	public static function init($type)
	{
		if(!self::$_instances)
		{
			self::$_instances = array();
		}
		if(!self::$_instances[$type])
		{
			self::$_instances[$type] = new Index($type);
		}
		return self::$_instances[$type];
	}

	/**
	 * Private constructor, so this class can only be used as a singleton.
	 *
	 * @param $type
	 *  The type
	 */
	private function __construct($type)
	{
		$this->_type  = $type;
		$this->_cache = new Cacheable(Symphony::Database());
		// Create an index:
		switch($this->_type)
		{
			case self::INDEX_PAGES :
				{
					$this->_path = PAGES.'/*.xml';
					$this->_element_name = 'pages';
					// Pages are overwritten with $overwrite set to false, to make use of the dirty-flag.
					$this->reIndex(false);
					break;
				}
			case self::INDEX_SECTIONS :
				{
					$this->_path = WORKSPACE.'/sections/*.xml';
					$this->_element_name = 'sections';
					// Sections are overwritten with $overwrite set to false, to make use of the dirty-flag.
					$this->reIndex(false);
				}
		}
	}

	/**
	 * Run an XPath expression on the index
	 *
	 * @param $path
	 *  The XPath expression
	 * @param bool $singleValue
	 *  Does this function return a single value?
	 * @return bool|SimpleXMLElement|SimpleXMLElement[]
	 */
	public function xpath($path, $singleValue = false)
	{
		if(!$singleValue)
		{
			return $this->_index->xpath('/'.$this->_type.'/'.$path);
		} else {
			$_result = $this->_index->xpath('/'.$this->_type.'/'.$path);
			if(count($_result) > 0)
			{
				return $_result[0];
			} else {
				return false;
			}
		}
	}

    /**
     * Check if there are duplicate hashes. Returns false if not, otherwise the hash in question
     *
     * @return bool|string
     */
    public function hasDuplicateHashes()
    {
        $_hashes = array();
        foreach($this->_index->children() as $_child)
        {
            if(!in_array((string)$_child->unique_hash, $_hashes))
            {
                $_hashes[] = (string)$_child->unique_hash;
            } else {
                return (string)$_child->unique_hash;
            }
        }
        return false;
    }

	/**
	 * Fetch the index
	 *
	 * @param string $xpath
	 *  An xpath expression to filter on
	 * @param $orderBy
	 *  The key to order by
	 * @param string $orderDirection
	 *  The direction (asc or desc)
	 * @param bool $sortNumeric
	 *  Should sorting be numeric (default) or as a string?
	 * @return array
	 *  An array with SimpleXMLElements
	 */
	public function fetch($xpath = null, $orderBy = null, $orderDirection = 'asc', $sortNumeric = true)
	{
		// Build the new array:
		$array = array();

		if($xpath != null)
		{
			$array = $this->xpath($xpath);
			if($array == false) { $array = array(); }
		} else {
			foreach($this->_index->children() as $_item)
			{
				$array[] = $_item;
			}
		}
		// Order the array:
		if($orderBy != null)
		{
			$sorter = array();
			// Create an indexed array of items:
			foreach($array as $_item)
			{
                // Prevent duplicate keys from being overwritten:
                $ok = true;
                $i  = 1;
                while($ok)
                {
                    $key = (string)$_item->$orderBy.'_'.$i;
                    if(!isset($sorter[$key]))
                    {
                        $sorter[$key] = $_item;
                        $ok = false;
                    }
                    $i++;
                }
			}
			// Sort the array:
			if($sortNumeric) {
				ksort($sorter, SORT_NUMERIC);
			} else {
				ksort($sorter, SORT_STRING);
			}
			if($orderDirection == 'desc')
			{
				$sorter = array_reverse($sorter);
			}
			// Build the new array:
			$array = array();
			foreach($sorter as $_item)
			{
				$array[] = $_item;
			}
		}
		return $array;
	}

	/**
	 * Get the maximum value of an item
	 *
	 * @param $name
	 *  The name of the element
	 * @return int
	 */
	public function getMax($name)
	{
		$_max = 0;
		foreach($this->_index->children() as $_item)
		{
			if((int)$_item->$name > $_max) { $_max = (int)$_item->$name; }
		}
		return $_max;
	}

	/**
	 * Setup the index. The index is a SimpleXMLElement which stores all information about all
	 * items. Therefore, the setup only needs to be loaded once, and not for each request.
	 *
	 * @param $overwrite bool
	 *  Automatically overwrite the index. If this is false, the cached index is used, instead of
	 *  the index built of the separate XML files.
	 *
	 * @return bool
	 *  true if a new index is built, false if the index from the cache is loaded
	 */
	public function reIndex($overwrite = true)
	{
		// Load the pages:
		$_files = glob($this->_path);

		// Build an array of md5-hashes, to check with the cached version:
		// This is done to detect if the XML-files in the folder have been changed.
		$_md5 = array();
		foreach($_files as $_file)
		{
			$_md5[] = md5_file($_file);
		}
		$_md5_hash = md5(implode(',', $_md5));

		// Check if the cached version is the same:
		$_data = $this->_cache->check('index:'.$this->_element_name);
		$_buildIndex = true;
		$this->_dirty = true;
		if($_data !== false)
		{
			// Load the cached XML:
			$this->_index = new SimpleXMLElement($_data['data']);
			// Check the MD5:
			if((string)$this->_index['md5'] == $_md5_hash)
			{
				// The cached result is the same as the directory, index does not need to be rebuilt.
				$_buildIndex = false;
				$this->_dirty = false;
			}
		} else {
			// No cached data found, this can mean either:
			// 1 - This is an empty installation. Create an empty index and cache it.
			// 2 - The cache is flushed and no local XML files are changed. Create a new index and cache it.
			// 3 - The cache is flushed and there are changes in the XML files. It's up to the managers to detect
			//     changes (by checking unique hashes in the lookup tables?)

			// Scenario 1:
			if(empty($_files))
			{
				// No files are found, store an empty index:
				$overwrite = true;
			} else {
				switch($this->_type)
				{
					case self::INDEX_PAGES :
						{
							$validate = PageManager::validateIndex($this->getLocalIndex());
							break;
						}
					case self::INDEX_SECTIONS :
						{
							$validate = SectionManager::validateIndex($this->getLocalIndex());
							break;
						}
				}
				if($validate)
				{
					// Scenario 2:
					// No local XML files changed, create a new index and cache it:
					$overwrite = true;
				} else {
					// Scenario 3:
					// todo: The local XML doesn't validate. This means that the cache was cleared ánd there were changes in
					// the XML file at the same time. For now, create an empty index, but this really needs to be some
					// thinking through, because this scenario could throw errors.
					$this->_index = new SimpleXMLElement('<'.$this->_element_name.'/>');
					$this->_index->addAttribute('md5', $_md5_hash);
				}
			}

/*			$this->_index = new SimpleXMLElement('<'.$this->_element_name.'/>');
			$this->_index->addAttribute('md5', $_md5_hash);
			if(!$overwrite)
			{
				// Set dirty to false, otherwise you would get a notification about changes:
				$this->_dirty = false;
			}*/
		}

		// Check if an index needs to be built:
		if($_buildIndex && $overwrite)
		{
			$this->_index = new SimpleXMLElement('<'.$this->_element_name.'/>');
			$this->_index->addAttribute('md5', $_md5_hash);
			foreach($_files as $_file)
			{
				$this->mergeXML($this->_index, simplexml_load_file($_file));
			}
			// Cache it:
			$this->_cache->write('index:'.$this->_element_name, $this->_index->saveXML());
			// Not dirty anymore:
			$this->_dirty = false;
		}

		/**
		 * Provide a hook to adjust the index
		 *
		 * @delegate IndexBuilt
		 * @since Symphony 2.4
		 * @param string $context
		 * '/global/'
		 * @param SimpleXMLElement $this->_index
		 *  A reference to the index
		 */
		Symphony::ExtensionManager()->notifyMembers('PostIndexBuilt',
			(class_exists('Administration') ? '/backend/' : '/frontend/'), array('index' => $this->_index));

		return $_buildIndex;
	}

	/**
	 * Merge SimpleXMLObjects
	 *
	 * @param $xml_element	SimpleXMLElement
	 * @param $append		SimpleXMLElement
	 * @return void
	 */
	public function mergeXML($xml_element, $append)
    {
        if ($append) {
            if (strlen(trim((string) $append))==0) {
                $xml = $xml_element->addChild($append->getName());
                foreach($append->children() as $child) {
                    $this->mergeXML($xml, $child);
                }
            } else {
                $xml = $xml_element->addChild($append->getName(), (string) $append);
            }
            foreach($append->attributes() as $n => $v) {
                $xml->addAttribute($n, $v);
            }
        }
    }

	/**
	 * Return the cached index
	 *
	 * @return SimpleXMLElement
	 */
	public function getIndex()
	{
		return $this->_index;
	}


	/**
	 * Return the local, non-cached index
	 *
	 * @return SimpleXMLElement
	 */
	public function getLocalIndex()
	{
		$index = new SimpleXMLElement('<'.$this->_element_name.'/>');
		$_files = glob($this->_path);
		foreach($_files as $_file)
		{
			@$xml = simplexml_load_file($_file);
			if($xml === false)
			{
				return false;
			} else {
				$this->mergeXML(&$index, $xml);
			}
		}
		return $index;
	}

	/**
	 * Edit the value of a node in the index (note: this does not save the index to a new file)
	 * If the node doesn't exists, it will be created
	 *
	 * @param $xpath
	 *  The XPath to the node to edit
	 * @param $value
	 *  The new value of the node
	 */
	public function editValue($xpath, $value)
	{
		$node = $this->xpath($xpath);
		if(count($node) == 0)
		{
			// Todo: Node doesn't exist, create a new node:
			
		} else {
			$node = $node[0];
			$node[0] = $value;
		}
	}

	/**
	 * Edit the value of a node in the index (note: this does not save the index to a new file)
	 *
	 * @param $xpath
	 *  The XPath to the node to edit
	 * @param $attribute
	 *  The name of the attribute to edit
	 * @param $value
	 *  The new value of the node
	 */
	public function editAttribute($xpath, $attribute, $value)
	{
		$node = $this->xpath($xpath);
		$node = $node[0];
		$node[$attribute] = $value;
	}

	/**
	 * Remove a node from the index
	 *
	 * @param $xpath
	 *  The XPath to the specific node to remove
	 */
	public function removeNode($xpath)
	{
		$nodes = $this->xpath($xpath);
		if($nodes != false)
		{
			foreach ($nodes as $item) {
				$node = dom_import_simplexml($item);
				$node->parentNode->removeChild($node);
			}
		}
	}

	/**
	 * Get formatted XML from the Index
	 *
	 * @param $xpath
	 *  The XPath to get the XML from
	 * @return string
	 *  The formatted XML
	 */
	public function getFormattedXML($xpath)
	{
		// Fetch all nodes and convert them to a XML string:
		$nodes = $this->xpath($xpath);
		$xmlString = '';
		foreach($nodes as $node)
		{
			$xmlString .= str_replace('<?xml version="1.0"?>', '', $node->saveXML());
		}

		// Make it pretty:
		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		$dom->loadXML($xmlString);

		// Return the XML string:
		return $dom->saveXML();
	}

	/**
	 * Checks whether the index is dirty. If the index is dirty, this means that the XML
	 * files differ from the cached XML and the cached XML is used.
	 *
	 * @return bool
	 */
	public function isDirty()
	{
		return $this->_dirty;
	}
}