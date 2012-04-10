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
		// Create an index:
		switch($this->_type)
		{
			case self::INDEX_PAGES :
				{
					$this->_path = PAGES.'/*.xml';
					$this->_element_name = 'pages';
					$this->reIndex();
					break;
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
	 */
	public function reIndex()
	{
		// Build the index:
		$this->_index = new SimpleXMLElement('<'.$this->_element_name.'/>');
		$_pages = glob($this->_path);
		foreach($_pages as $_page)
		{
			$this->mergeXML($this->_index, simplexml_load_file($_page));
		}
	}

	/**
	 * @param $xml_element	SimpleXMLElement
	 * @param $append		SimpleXMLElement
	 * @return void
	 */
	private function mergeXML($xml_element, $append)
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

}