<?php

	/**
	 * @package toolkit
	 */
	/**
	 * The `SectionManager` is responsible for managing all Sections in a Symphony
	 * installation by exposing basic CRUD operations. Sections are stored in the
	 * database in `tbl_sections`.
	 */
	include_once(TOOLKIT . '/class.section.php');

	Class SectionManager {

		/**
		 * An array of all the objects that the Manager is responsible for.
		 *
		 * @var array
		 *   Defaults to an empty array.
		 */
		protected static $_pool = array();

		/**
		 * Return a reference to the sections index
		 *
		 * @return Index
		 */
		public static function index()
		{
			return Index::init(Index::INDEX_SECTIONS);
		}

		/**
		 * Return a reference to the sections lookup
		 *
		 * @return Lookup
		 */
		public static function lookup()
		{
			return Lookup::init(Lookup::LOOKUP_SECTIONS);
		}

		/**
		 * Takes an associative array of Section settings and creates a new
		 * entry in the `tbl_sections` table, returning the ID of the Section.
		 * The ID of the section is generated using auto_increment and returned
		 * as the Section ID.
		 *
		 * @param array $settings
		 *  An associative of settings for a section with the key being
		 *  a column name from `tbl_sections`
		 * @return integer
		 *  The newly created Section's ID
		 */
		public static function add(array $settings){
			$hash = self::__generateSectionXML($settings);

			$id   = self::lookup()->save($hash);
			return $id;
		}

		/**
		 * Generate the Section XML
		 *
		 * @param $fields
		 *  Associative array of fields names => values for the Section
		 * @return string
		 *  The unique hash of this section
		 */
		private function __generateSectionXML($fields)
		{
			// Generate Section XML-file:
			// Generate a unique hash, this only happens the first time this page is created:
			if(!isset($fields['unique_hash']))
			{
				$fields['unique_hash'] = md5('section'.$fields['name'].time());
			}

			// Generate fields XML:
			$fields_str = '';
			// Generate the main XML:
			$dom = new DOMDocument();
			$dom->preserveWhiteSpace = false;
			$dom->formatOutput = true;
			$dom->loadXML(sprintf('
				<section>
					<name handle="%6$s">%1$s</name>
					<sortorder>%7$s</sortorder>
					<hidden>%2$s</hidden>
					<navigation_group>%3$s</navigation_group>
					<unique_hash>%4$s</unique_hash>
					<fields>%5$s</fields>
				</section>
				',
				$fields['name'],
				(isset($fields['hidden']) ? $fields['hidden'] : 'no'),
				$fields['navigation_group'],
				$fields['unique_hash'],
				$fields_str,
				General::createHandle($fields['name']),
				$fields['sortorder']
			));

			// Save the XML:
			self::__saveXMLFile(General::createHandle($fields['name']), $dom->saveXML());

			return $fields['unique_hash'];
		}

		/**
		 * Save a section XML file
		 *
		 * @param $handle
		 *  The handle of the section
		 * @param $xml
		 *  The XML data
		 * @return bool
		 *  true on success, false on failure
		 */
		public static function __saveXMLFile($handle, $xml, $reIndex = true)
		{
			$ok = General::writeFile(WORKSPACE.'/sections/'.$handle.'.xml', $xml,
				Symphony::Configuration()->get('write_mode', 'file')
			);
			if($reIndex)
			{
				// Re-index (since the XML files are changed):
				// Todo: optimize the code with a save-function at the end?
				self::index()->reIndex();
			}
			return $ok;
		}

		/**
		 * Save the section to an XML file according to it's ID.
		 *
		 * @param $section_id
		 *  The ID of the section
		 * @param $reIndex
		 *  Is a reIndex required?
		 * @return bool
		 *  true on success, false on failure
		 */
		public static function saveSection($section_id, $reIndex = true)
		{
			$hash   = self::lookup()->getHash($section_id);
			$handle = self::index()->xpath(
				sprintf('section[unique_hash=\'%s\']/name/@handle', $hash), true
			);
			return self::__saveXMLFile($handle,
				self::index()->getFormattedXML(sprintf('section[unique_hash=\'%s\']', $hash)), $reIndex
			);
		}

		/**
		 * This function checks if sections are added, edited or deleted outside Symphony
		 */
		public static function checkIndex()
		{
			if(self::index()->isDirty())
			{
				$callback = Administration::instance()->getPageCallback();
				if($callback['driver'] != 'login')
				{
					// The index is dirty. Show a message to go to the diff page.
					Administration::instance()->Page->pageAlert(
						sprintf(__('One or more sections are modified outside of Symphony. <a href="%s">Show differences</a>'),
						SYMPHONY_URL.'/blueprints/sections/diff/'),
						Alert::ERROR
					);
				}
			}
		}

		/**
		 * Updates an existing Section given it's ID and an associative
		 * array of settings. The array does not have to contain all the
		 * settings for the Section as there is no deletion of settings
		 * prior to updating the Section
		 *
		 * @param integer $section_id
		 *  The ID of the Section to edit
		 * @param array $settings
		 *  An associative of settings for a section with the key being
		 *  a column name from `tbl_sections`
		 * @return boolean
		 */
		public static function edit($section_id, array $settings){

			$hash = self::lookup()->getHash($section_id);

			$old_handle = self::index()->xpath(sprintf('section[unique_hash=\'%s\']/name/@handle', $hash), true);

			// Edit the index:
			foreach($settings as $key => $value)
			{
				if($key != 'handle')
				{
					self::index()->editValue(sprintf('section[unique_hash=\'%s\']/%s', $hash, $key), $value);
				} else {
					self::index()->editAttribute(sprintf('section[unique_hash=\'%s\']/name', $hash, $key), 'handle', $value);
				}
			}

			// Delete the old XML:
			General::deleteFile(WORKSPACE.'/sections/'.$old_handle.'.xml');

			// Save the new XML:
			self::__saveXMLFile(
				self::index()->xpath(sprintf('section[unique_hash=\'%s\']/name/@handle', $hash), true),
				self::index()->getFormattedXML(sprintf('section[unique_hash=\'%s\']', $hash))
			);

			return true;
		}

		/**
		 * Deletes a Section by Section ID, removing all entries, fields, the
		 * Section and any Section Associations in that order
		 *
		 * @param integer $section_id
		 *  The ID of the Section to delete
		 * @param boolean
		 *  Returns true when completed
		 */
		public static function delete($section_id){

			$hash 		= self::lookup()->getHash($section_id);
			$section    = self::fetchByXPath(sprintf('section[unique_hash=\'%s\']', $hash));
			//$sortorder	= self::index()->xpath(sprintf('section[unique_hash=\'%s\']/sortorder', $hash), true);

			// Delete all the entries
			include_once(TOOLKIT . '/class.entrymanager.php');
			$entries = Symphony::Database()->fetchCol('id', "SELECT `id` FROM `tbl_entries` WHERE `section_id` = '$section_id'");
			EntryManager::delete($entries);

			// Delete all the fields
			$fields = FieldManager::fetch(null, $section_id);

			if(is_array($fields) && !empty($fields)){
				foreach($fields as $field) FieldManager::delete($field->get('id'));
			}

			// Delete the section file:
			unlink(WORKSPACE.'/sections/'.$section->get('handle').'.xml');

			// Update the sort orders?
			// Todo: is this necessary?

			// Delete the section associations:
			$sections = self::index()->fetch(
				sprintf('section/associations/association[parent_section=\'%s\']', $hash)
			);
			foreach($sections as $section)
			{
				self::index()->removeNode(sprintf('associations/association[parent_section=\'%s\']', (string)$section->unique_hash));
				// Save the section:
				self::saveSection(self::lookup()->getId((string)$section->unique_hash));
			}

			// Delete the section lookup:
			self::lookup()->delete($hash);

			// ReIndex:
			self::index()->reIndex();

			return true;
		}

		/**
		 * This function will return an array of Section Objects or a single Section.
		 * Optionally, the `$xpath`, `$order_by` and `$order_direction` parameters
		 * allow a developer to further refine their query.
		 *
		 * @param string $xpath (optional)
		 *  A XPath expression to filter sections out of the Sections Index.
		 * @param string $order_by (optional)
		 *  Allows a developer to return the Sections in a particular order. If omitted
		 *  this will return sections ordered by `sortorder`.
		 * @param string $order_direction (optional)
		 *  The direction to order (`asc` or `desc`)
		 *  Defaults to `asc`
		 * @return Section|array
		 *  A Section object or an array of Section objects
		 */
		public static function fetchByXPath($xpath = 'section', $order_by = 'sortorder', $order_direction = 'asc') {
			$_sections = self::index()->fetch($xpath, $order_by, $order_direction);

			$returnSingle = false;

			if($xpath != 'section') {
				if(count($_sections) == 1) {
					$returnSingle = true;
				}
			}

			$ret = array();

			foreach($_sections as $s){
				$obj = self::create();

				$obj->set('name', 				(string)$s->name);
				$obj->set('handle', 			(string)$s->name['handle']);
				$obj->set('sortorder',			(string)$s->sortorder);
				$obj->set('hidden',				(string)$s->hidden);
				$obj->set('navigation_group',	(string)$s->navigation_group);
				$obj->set('unique_hash',		(string)$s->unique_hash);

				$obj->set('id', self::lookup()->getId((string)$s->unique_hash));

				// Todo: entry_order
				// Todo: entry_order_direction

/*				foreach($s as $name => $value){
					$obj->set($name, $value);
				}*/

				self::$_pool[$obj->get('id')] = $obj;

				$ret[] = $obj;
			}

			return (count($ret) == 1 && $returnSingle ? $ret[0] : $ret);
		}

		/**
		 * Returns a Section object by ID, or returns an array of Sections
		 * if the Section ID was omitted. If the Section ID is omitted, it is
		 * possible to sort the Sections by providing a sort order and sort
		 * field. By default, Sections will be order in ascending order by
		 * their name
		 *
		 * @deprecated since 2.4
		 *
		 * @param integer|array $section_id
		 *  The ID of the section to return, or an array of ID's. Defaults to null
		 * @param string $order
		 *  If `$section_id` is omitted, this is the sortorder of the returned
		 *  objects. Defaults to ASC, other options id DESC
		 * @param string $sortfield
		 *  The name of the column in the `tbl_sections` table to sort
		 *  on. Defaults to name
		 * @return Section|array
		 *  A Section object or an array of Section objects
		 */
		public static function fetch($section_id = null, $order = 'ASC', $sortfield = 'name'){

			if($section_id == null)
			{
				return self::fetchByXPath();
			} else {
				$_hash = self::lookup()->getHash($section_id);
				return self::fetchByXPath(
					sprintf('section[unique_hash=\'%s\']', $_hash),
					trim(strtolower($sortfield)),
					trim(strtolower($order))
				);
			}
		}

		/**
		 * Return a Section ID by the handle
		 *
		 * @param string $handle
		 *  The handle of the section
		 * @return integer
		 *  The Section ID
		 */
		public static function fetchIDFromHandle($handle){
			return self::lookup()->getId(self::index()->xpath(
				sprintf('section[name/@handle=\'%s\']/unique_hash', $handle), true));
		}

		/**
		 * Work out the next available sort order for a new section
		 *
		 * @return integer
		 *  Returns the next sort order
		 */
		public static function fetchNextSortOrder(){
			$next = self::index()->getMax('sortorder');
			return ($next ? (int)$next : 1);
		}

		/**
		 * Returns a new Section object, using the SectionManager
		 * as the Section's $parent.
		 *
		 * @return Section
		 */
		public static function create(){
			$obj = new Section;
			return $obj;
		}

		/**
		 * Create an association between a section and a field.
		 *
		 * @since Symphony 2.3
		 * @param integer $parent_section_id
		 *  The linked section id.
		 * @param integer $child_field_id
		 *  The field ID of the field that is creating the association
		 * @param integer $parent_field_id (optional)
		 *  The field ID of the linked field in the linked section
		 * @param boolean $show_association (optional)
		 *  Whether of not the link should be shown on the entries table of the
		 *  linked section. This defaults to true.
		 * @return boolean
		 *  true if the association was successfully made, false otherwise.
		 */
		public static function createSectionAssociation($parent_section_id = null, $child_field_id = null, $parent_field_id = null, $show_association = true){

			if(is_null($parent_section_id) && (is_null($parent_field_id) || !$parent_field_id)) return false;

			if(is_null($parent_section_id )) {
				$parent_field = FieldManager::fetch($parent_field_id);
				$parent_section_id = $parent_field->get('parent_section');
			}

			$child_field = FieldManager::fetch($child_field_id);
			$child_section_id = $child_field->get('parent_section');

			$parent_section_hash	= self::lookup()->getHash($parent_section_id);
			$parent_field_hash		= FieldManager::lookup()->getHash($parent_field_id);
			$child_section_hash		= self::lookup()->getHash($child_section_id);
			$child_field_hash		= FieldManager::lookup()->getHash($child_field_id);

			// Save the association in the section XML:
			// Check if the associations node exists:
			$nodes = self::index()->xpath(
				sprintf('section[unique_hash=\'%s\']/associations', $parent_section_hash)
			);
			if(empty($nodes))
			{
				// Add the associations node:
				var_dump($parent_field->get('parent_section'));
				self::index()->xpath(
					sprintf('section[unique_hash=\'%s\']', $parent_section_hash), true
				)->addChild('associations');
			}
			// Add the association:
			$node = self::index()->xpath(
				sprintf('section[unique_hash=\'%s\']/associations', $parent_section_hash), true
			)->addChild('association');

			$node->addChild('parent_field', $parent_field_hash);
			$node->addChild('child_section', $child_section_hash);
			$node->addChild('child_field', $child_field_hash);
			$node->addChild('show_association', ($show_association ? 'no' : 'yes'));

			// Save the section:
			return self::saveSection($parent_section_id);
		}

		/**
		 * Permanently remove a section association for this field in the database.
		 *
		 * @since Symphony 2.3
		 * @param integer $child_field_id
		 *  the field ID of the linked section's linked field.
		 * @return boolean
		 */
		public static function removeSectionAssociation($child_field_id) {
			// Get the sections with the association:
			$sections = self::index()->xpath(
				sprintf('section[associations/association/child_field=\'%s\']', FieldManager::lookup()->getHash($child_field_id))
			);

			// Remove the nodes:
			self::index()->removeNode(
				sprintf('section/associations/association[child_field=\'%s\']', FieldManager::lookup()->getHash($child_field_id))
			);

			// Save the sections:
			foreach($sections as $section)
			{
				self::saveSection(self::lookup()->getId((string)$section->unique_hash));
			}

			return true;
		}

		/**
		 * Returns any section associations this section has with other sections
		 * linked using fields. Has an optional parameter, `$respect_visibility` that
		 * will only return associations that are deemed visible by a field that
		 * created the association. eg. An articles section may link to the authors
		 * section, but the field that links these sections has hidden this association
		 * so an Articles column will not appear on the Author's Publish Index
		 *
		 * @since Symphony 2.3
		 * @param integer $section_id
		 *  The ID of the section
		 * @param boolean $respect_visibility
		 *  Whether to return all the section associations regardless of if they
		 *  are deemed visible or not. Defaults to false, which will return all
		 *  associations.
		 * @return array
		 */
		public static function fetchAssociatedSections($section_id, $respect_visibility = false) {
			$xpath = sprintf('section[unique_hash=\'%s\']/associations/association', self::lookup()->getHash($section_id));
			if($respect_visibility)
			{
				$xpath .= '[show_association=\'yes\']';
			}
			$associations = self::index()->xpath($xpath);

			$result = array();

			if(is_array($associations))
			{
				foreach($associations as $association)
				{
					$result[] = array(
						'parent_section_id'			=> $section_id,
						'parent_section_field_id'	=> FieldManager::lookup()->getId((string)$association->parent_field),
						'child_section_id'			=> self::lookup()->getId((string)$association->child_section),
						'child_section_field_id'	=> FieldManager::lookup()->getId((string)$association->child_field),
						'show_association' 			=> (string)$association->show_assocation,
						// For backward compatibility:
						'hide_association'			=> ((string)$association->show_assocation == 'no' ? 'yes' : 'no')
					);
				}
			}

			return $result;
		}

	}
