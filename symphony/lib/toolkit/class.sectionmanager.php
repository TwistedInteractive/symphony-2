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

			if(!Symphony::Database()->insert($settings, 'tbl_sections')) return false;

			self::__generateSectionXML($settings);

			return Symphony::Database()->getInsertID();
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
				$fields['unique_hash'] = md5($fields['name'].time());
			}

			// Generate datasources-xml:
/*			$datasources = empty($fields['data_sources']) ? '' :
				'<datasource>'.implode('</datasource><datasource>', explode(',', $fields['data_sources'])) .'</datasource>';

			// Generate events-xml:
			$events = empty($fields['events']) ? '' :
				'<event>'.implode('</event><event>', explode(',', $fields['events'])) .'</event>';

			// Generate types-xml:
			$types = empty($fields['type']) ? '' :
				'<type>'.implode('</type><type>', $fields['type']) .'</type>';*/

			/*
<section>
    <name>News items</name>
    <sortorder>1</sortorder>
    <hidden>false</hidden>
    <navigation_group>Content</navigation_group>
    <unique_hash>bb2c28e57b6f2cd75723f67948e3c73c</unique_hash>
    <fields>
        <field>
            <label element_name="name">Name</label>
            <unique_hash>97dcae5e3bd3c9fb78fa302c0a083947</unique_hash>
            <type>input</type>
            <required>true</required>
            <sortorder>1</sortorder>
            <location>main</location>
            <show_column>true</show_column>
            <configuration>
                <validator />
            </configuration>
        </field>
    </fields>
</section>
			 */

			$fields_str = '';

			// Generate the main XML:
			$dom = new DOMDocument();
			$dom->preserveWhiteSpace = false;
			$dom->formatOutput = true;
			$dom->loadXML(sprintf('
				<section>
					<name>%1$s</name>
					<sortorder>%2$s</sortorder>
					<hidden>%2$s</hidden>
					<navigation_group>%3$s</navigation_group>
					<unique_hash>%4$s</unique_hash>
					<fields>%5$s</fields>
				</section>
				',
				$fields['name'],
				self::fetchNextSortOrder(),
				(isset($fields['hidden']) ? 'true' : 'false'),
				$fields['navigation_group'],
				$fields['unique_hash'],
				$fields_str
			));

			// Save the XML:
			General::writeFile(
				WORKSPACE.'/sections/'.General::createHandle($fields['name']).'.xml',
				$dom->saveXML(),
				Symphony::Configuration()->get('write_mode', 'file')
			);

			// Re-index:
			// @Todo: optimize the code with a save-function at the end?
			self::index()->reIndex();

			return $fields['unique_hash'];
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
			if(!Symphony::Database()->update($settings, 'tbl_sections', " `id` = $section_id")) return false;

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
			$details = Symphony::Database()->fetchRow(0, "SELECT `sortorder` FROM tbl_sections WHERE `id` = '$section_id'");

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
			Symphony::Database()->delete('tbl_sections_association', " `parent_section_id` = '$section_id'");

			return true;
		}

		/**
		 * Returns a Section object by ID, or returns an array of Sections
		 * if the Section ID was omitted. If the Section ID is omitted, it is
		 * possible to sort the Sections by providing a sort order and sort
		 * field. By default, Sections will be order in ascending order by
		 * their name
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
			$returnSingle = false;
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

			return (count($ret) == 1 && $returnSingle ? $ret[0] : $ret);
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
			return Symphony::Database()->fetchVar('id', 0, "SELECT `id` FROM `tbl_sections` WHERE `handle` = '$handle' LIMIT 1");
		}

		/**
		 * Work out the next available sort order for a new section
		 *
		 * @return integer
		 *  Returns the next sort order
		 */
		public static function fetchNextSortOrder(){
			$next = Symphony::Database()->fetchVar("next", 0, "
				SELECT
					MAX(p.sortorder) + 1 AS `next`
				FROM
					`tbl_sections` AS p
				LIMIT 1
			");
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

			$fields = array(
				'parent_section_id' => $parent_section_id,
				'parent_section_field_id' => $parent_field_id,
				'child_section_id' => $child_section_id,
				'child_section_field_id' => $child_field_id,
				'hide_association' => ($show_association ? 'no' : 'yes')
			);

			return Symphony::Database()->insert($fields, 'tbl_sections_association');
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
			return Symphony::Database()->delete('tbl_sections_association', sprintf(" `child_section_field_id` = %d ", $child_field_id));
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
			return Symphony::Database()->fetch(sprintf("
					SELECT *
					FROM `tbl_sections_association` AS `sa`, `tbl_sections` AS `s`
					WHERE `sa`.`parent_section_id` = %d
					AND `s`.`id` = `sa`.`child_section_id`
					%s
					ORDER BY `s`.`sortorder` ASC
				",
				$section_id,
				($respect_visibility) ? "AND `sa`.`hide_association` = 'no'" : ""
			));
		}

	}
