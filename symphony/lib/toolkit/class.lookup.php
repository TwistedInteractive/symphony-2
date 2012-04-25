<?php
/**
 * Created by JetBrains PhpStorm.
 * User: Giel
 * Date: 29-03-12
 * Time: 22:39
 * File: class.managerlookup.php
 */
 
class Lookup
{
	const LOOKUP_PAGES 		= 'pages';
	const LOOKUP_SECTIONS 	= 'sections';
	const LOOKUP_FIELDS 	= 'fields';

	// Instance is used for singleton:
	private static $_instances;

	// Keep track of the type
	private $_type;

	// Internal cache (for this class)
	private $_cache;

	/**
	 * Get the index
	 *
	 * @param $type
	 *  The type of the index
	 * @return Lookup
	 */
	public static function init($type)
	{
		if(!self::$_instances)
		{
			self::$_instances = array();
		}
		if(!self::$_instances[$type])
		{
			self::$_instances[$type] = new Lookup($type);
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
	}

	/**
	 * Save a lookup
	 *
	 * @param $hash
	 *  The unique hash of the item that is saved
	 * @return int
	 *  The ID internal used in the database.
	 */
	public function save($hash)
	{
		Symphony::Database()->insert(array('hash'=>$hash), 'tbl_lookup_'.$this->_type);
		return Symphony::Database()->getInsertID();
	}

	/**
	 * Delete a lookup
	 *
	 * @param $idOrHash
	 *  The ID of the item, or the hash value
	 * @return void
	 */
	public function delete($idOrHash)
	{
		if(is_numeric($idOrHash) && strlen($idOrHash) != 32)
		{
			// Assume it's an ID
			Symphony::Database()->delete('tbl_lookup_'.$this->_type, '`id` = '.$idOrHash);
		} else {
			// Assume it's a hash
			Symphony::Database()->delete('tbl_lookup_'.$this->_type, '`hash` = \''.$idOrHash.'\'');
		}
	}

	/**
	 * Return the ID according to the hash
	 *
	 * @param $hash
	 *  The hash
	 * @return int
	 */
	public function getId($hash)
	{
		// The key of an associated index may not begin with a number:
		$key = 'c_'.$hash;
		if(!isset($this->_cache['hash'][$key]))
		{
			$this->_cache['hash'][$key] = Symphony::Database()->fetchVar('id', 0,
				sprintf('SELECT `id` FROM `tbl_lookup_%s` WHERE `hash` = \'%s\';', $this->_type, $hash));
		}
		return $this->_cache['hash'][$key];
	}

	/**
	 * Return the hash according to the ID
	 *
	 * @param $id
	 *  The id
	 * @return string
	 */
	public function getHash($id)
	{
		if(!isset($this->_cache['id'][$id]))
		{
			$this->_cache['id'][$id] = Symphony::Database()->fetchVar('hash', 0,
				sprintf('SELECT `hash` FROM `tbl_lookup_%s` WHERE `id` = %d;', $this->_type, $id));
		}
		return $this->_cache['id'][$id];
	}

    /**
     * Get all used hashes
     *
     * @return array
     */
    public function getAllHashes()
    {
		return Symphony::Database()->fetchCol('hash', 'SELECT `hash` FROM `tbl_lookup_'.$this->_type.'`;');
    }

}
