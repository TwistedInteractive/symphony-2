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
/*			print_r($settings);
			die();*/

			$hash = self::__generateSectionXML($settings);

			$id   = self::lookup()->save($hash);
			return $id;

			// unset($settings['fields']);
			// if(!Symphony::Database()->insert($settings, 'tbl_sections')) return false;


			// return Symphony::Database()->getInsertID();
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
			/*
			if(!empty($fields['fields']))
			{
				$sortorder = 1;
				foreach($fields['fields'] as $field)
				{
					// Generate a unique hash for this field:
					if(!isset($field['unique_hash']))
					{
						$field['unique_hash'] = md5('field.'.$field['label'].time());
					}

					// Store the configuration fields:
					$configuration = '';
					// Get the available configuration fields from the field itself:
					$configuration_fields = FieldManager::create($field['type'])->getConfiguration();
					if(!empty($configuration_fields))
					{
						foreach($configuration_fields as $configuration_field)
						{
							$configuration .= '<'.$configuration_field.'>'.$field[$configuration_field].'</'.$configuration_field.'>';
						}
					}

					$fields_str .= sprintf(
						'<field>
							<label element_name="%1$s">%2$s</label>
							<unique_hash>%3$s</unique_hash>
							<type>%4$s</type>
							<required>%5$s</required>
							<sortorder>%6$s</sortorder>
							<location>%7$s</location>
							<show_column>%8$s</show_column>
							<configuration>%9$s</configuration>
						</field>',
						$field['element_name'],
						$field['label'],
						$field['unique_hash'],
						$field['type'],
						(isset($field['required']) ? $field['required'] : 'no'),
						$sortorder,
						$field['location'],
						$field['show_column'],
						$configuration
					);
					$sortorder++;
				}
			}
*/


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
		public static function __saveXMLFile($handle, $xml)
		{
			$ok = General::writeFile(WORKSPACE.'/sections/'.$handle.'.xml', $xml,
				Symphony::Configuration()->get('write_mode', 'file')
			);
			// Re-index (since the XML files are changed):
			// Todo: optimize the code with a save-function at the end?
			self::index()->reIndex();

			return $ok;
		}

		/**
		 * Save the section to an XML file according to it's ID.
		 *
		 * @param $section_id
		 *  The ID of the section
		 * @return bool
		 *  true on success, false on failure
		 */
		public function saveSection($section_id)
		{
			$hash   = self::lookup()->getHash($section_id);
			$handle = self::index()->xpath(
				sprintf('section[unique_hash=\'%s\']/name/@handle', $hash), true
			);
			return self::__saveXMLFile($handle,
				self::index()->getFormattedXML(sprintf('section[unique_hash=\'%s\']', $hash))
			);
		}

		/**
		 * This function checks if sections are added, edited or deleted outside Symphony
		 */
		public static function checkIndex()
		{
			if(self::index()->isDirty())
			{
				// The index is dirty. Show a message to go to the diff page.
				Administration::instance()->Page->pageAlert(
					sprintf(__('One or more sections are modified outside of Symphony. <a href="%s">Show differences</a>'),
					SYMPHONY_URL.'/blueprints/sections/diff/'),
					Alert::ERROR
				);
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

			// $section = self::fetchByXPath(sprintf('section[unique_hash=\'%s\']', $hash));
/*			$section = self::index()->xpath(sprintf('section[unique_hash=\'%s\']', $hash));
			$section = $section[0];*/

			// Load the section data:
/*			$_data = array(
				'name' => 				(string)$section->name,
				'sortorder' => 			(string)$section->sortorder,
				'navigation_group' =>	(string)$section->navigation_group,
				'hidden' =>				(string)$section->hidden
			);*/

			// Load the fields:
/*			if(!empty($section->fields))
			{
				foreach($section->fields->children() as $field)
				{
					$fields = array(
						'label' =>			(string)$field->label,
						'element_name' =>	(string)$field->label['element_name'],
						'unique_hash' => 	(string)$field->unique_hash,
						'type' =>			(string)$field->type,
						'required' =>		(string)$field->required,
						'sortorder' =>		(string)$field->sortorder,
						'location' =>		(string)$field->location,
						'show_column' =>	(string)$field->yes
					);
					// Append the configuration fields:
					if(!empty($field->configuration))
					{
						foreach($field->configuration->children() as $child)
						{
							$fields[$child->getName()] = (string)$child;
						}
					}
					$_data['fields'][] = $fields;
				}
			}*/
			// merge the arrays:
/*			foreach($settings as $key => $value)
			{
				$_data[$key] = $value;
			}*/


			// self::__generateSectionXML($settings);

/*			unset($settings['fields']);
			if(!Symphony::Database()->update($settings, 'tbl_sections', " `id` = $section_id")) return false;*/

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

			// Delete the section lookup:
			self::lookup()->delete($hash);

			// Delete the section file:
			unlink(WORKSPACE.'/sections/'.$section->get('handle').'.xml');

			// Update the sort orders?
			// Todo: is this necesarry?

			// Delete the section associations?
			// Todo: associations are going to be stored in the XML-files


/*			$details = Symphony::Database()->fetchRow(0, "SELECT `sortorder` FROM tbl_sections WHERE `id` = '$section_id'");

			// Delete all the entries
			include_once(TOOLKIT . '/class.entrymanager.php');
			$entries = Symphony::Database()->fetchCol('id', "SELECT `id` FROM `tbl_entries` WHERE `section_id` = '$section_id'");
			EntryManager::delete($entries);

			// Delete all the fields
			$fields = FieldManager::fetch(null, $section_id);

			if(is_array($fields) && !empty($fields)){
				foreach($fields as $field) FieldManager::delete($field->get('id'));
			}

			// Delete the section
			Symphony::Database()->delete('tbl_sections', " `id` = '$section_id'");

			// Update the sort orders
			Symphony::Database()->query("UPDATE tbl_sections SET `sortorder` = (`sortorder` - 1) WHERE `sortorder` > '".$details['sortorder']."'");

			// Delete the section associations
			Symphony::Database()->delete('tbl_sections_association', " `parent_section_id` = '$section_id'");*/



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

/*			$returnSingle = false;
			$section_ids = array();

			if(!is_null($section_id)) {
				if(!is_array($section_id)) {
					$returnSingle = true;
					$section_ids = array((int)$section_id);
				}
				else {
					$section_ids = $section_id;
				}
			}

			if($returnSingle && isset(self::$_pool[$section_id])){
				return self::$_pool[$section_id];
			}

			$sql = sprintf("
					SELECT `s`.*
					FROM `tbl_sections` AS `s`
					%s
					%s
				",
				!empty($section_id) ? " WHERE `s`.`id` IN (" . implode(',', $section_ids) . ") " : "",
				empty($section_id) ? " ORDER BY `s`.`$sortfield` $order" : ""
			);

			if(!$sections = Symphony::Database()->fetch($sql)) return ($returnSingle ? false : array());

			$ret = array();

			foreach($sections as $s){
				$obj = self::create();

				foreach($s as $name => $value){
					$obj->set($name, $value);
				}

				self::$_pool[$obj->get('id')] = $obj;

				$ret[] = $obj;
			}

			return (count($ret) == 1 && $returnSingle ? $ret[0] : $ret);*/
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

			// return Symphony::Database()->fetchVar('id', 0, "SELECT `id` FROM `tbl_sections` WHERE `handle` = '$handle' LIMIT 1");
		}

		/**
		 * Work out the next available sort order for a new section
		 *
		 * @return integer
		 *  Returns the next sort order
		 */
		public static function fetchNextSortOrder(){
/*			$next = Symphony::Database()->fetchVar("next", 0, "
				SELECT
					MAX(p.sortorder) + 1 AS `next`
				FROM
					`tbl_sections` AS p
				LIMIT 1
			");*/

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

/*			<associations>
				<association>
					<parent_field></parent_field>
					<child_section></child_section>
					<child_field></child_field>
					<show_association></show_association>
				</association>
			</associations>*/


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

/*			$fields = array(
				'parent_section_id' => $parent_section_id,
				'parent_section_field_id' => $parent_field_id,
				'child_section_id' => $child_section_id,
				'child_section_field_id' => $child_field_id,
				'hide_association' => ($show_association ? 'no' : 'yes')
			);

			return Symphony::Database()->insert($fields, 'tbl_sections_association');*/
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

			// return Symphony::Database()->delete('tbl_sections_association', sprintf(" `child_section_field_id` = %d ", $child_field_id));
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

/*			return Symphony::Database()->fetch(sprintf("
					SELECT *
					FROM `tbl_sections_association` AS `sa`, `tbl_sections` AS `s`
					WHERE `sa`.`parent_section_id` = %d
					AND `s`.`id` = `sa`.`child_section_id`
					%s
					ORDER BY `s`.`sortorder` ASC
				",
				$section_id,
				($respect_visibility) ? "AND `sa`.`hide_association` = 'no'" : ""
			));*/


		}

	}
