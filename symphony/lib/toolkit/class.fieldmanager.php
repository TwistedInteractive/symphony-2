<?php

	/**
	 * @package toolkit
	 */
	/**
	 * The FieldManager class is responsible for managing all fields types in Symphony.
	 * Fields are stored on the file system either in the `/fields` folder of `TOOLKIT` or
	 * in a `fields` folder in an extension directory.
	 */

	require_once(TOOLKIT . '/class.field.php');

	Class FieldManager implements FileResource {

		/**
		 * An array of all the objects that the Manager is responsible for.
		 * Defaults to an empty array.
		 * @var array
		 */
		protected static $_pool = array();

		/**
		 * An array of all fields whose have been created by ID
		 * @var array
		 */
		private static $_initialiased_fields = array();

		/**
		 * Return the Section Index. For fields, the Section Index is used, since fields are stored inside the Sections'
		 * XML files.
		 *
		 * @return Index
		 *  The Section Index
		 */
		public static function index()
		{
			// The fields use the same index as the sections, since fields are stored in sections
			return Index::init(Index::INDEX_SECTIONS);
		}

		/**
		 * Return the Lookup for Fields.
		 *
		 * @return Lookup
		 */
		public static function lookup()
		{
			return Lookup::init(Lookup::LOOKUP_FIELDS);
		}


		/**
		 * Given the filename of a Field, return it's handle. This will remove
		 * the Symphony conventions of `field.*.php`
		 *
		 * @param string $filename
		 *  The filename of the Field
		 * @return string
		 */
		public static function __getHandleFromFilename($filename){
			return preg_replace(array('/^field./i', '/.php$/i'), '', $filename);
		}

		/**
		 * Given a type, returns the full class name of a Field. Fields use a
		 * 'field' prefix
		 *
		 * @param string $type
		 *  A field handle
		 * @return string
		 */
		public static function __getClassName($type){
			return 'field' . $type;
		}

		/**
		 * Finds a Field by type by searching the `TOOLKIT . /fields` folder and then
		 * any fields folders in the installed extensions. The function returns
		 * the path to the folder where the field class resides.
		 *
		 * @param string $name
		 *  The field handle, that is, `field.{$handle}.php`
		 * @return string
		 */
		public static function __getClassPath($type){
			if(is_file(TOOLKIT . "/fields/field.{$type}.php")) return TOOLKIT . '/fields';
			else{

				$extensions = Symphony::ExtensionManager()->listInstalledHandles();

				if(is_array($extensions) && !empty($extensions)){
					foreach($extensions as $e){
						if(is_file(EXTENSIONS . "/{$e}/fields/field.{$type}.php")) return EXTENSIONS . "/{$e}/fields";
					}
				}
			}

			return false;
		}

		/**
		 * Given a field type, return the path to it's class
		 *
		 * @see __getClassPath()
		 * @param string $type
		 *  The handle of the field to load (it's type)
		 * @return string
		 */
		public static function __getDriverPath($type){
			return self::__getClassPath($type) . "/field.{$type}.php";
		}

		/**
		 * This function is not implemented by the `FieldManager` class
		 */
		public static function about($name) {
			return false;
		}

		/**
		 * Given an associative array of fields, insert them into the database
		 * returning the resulting Field ID if successful, or false if there
		 * was an error. As fields are saved in order on a section, a query is
		 * made to determine the sort order of this field to be current sort order
		 * +1.
		 *
		 * @param array $fields
		 *  Associative array of field names => values for the Field object
		 * @return integer|boolean
		 *  Returns a Field ID of the created Field on success, false otherwise.
		 */
		public static function add(array $fields){

			if(!isset($fields['sortorder'])){
				$fields['sortorder'] = self::fetchNextSortOrder();
			}

			$_hash = self::__generateXML($fields);

			$field_id = self::lookup()->save($_hash);

/*			if(!Symphony::Database()->insert($fields, 'tbl_fields')) return false;
			$field_id = Symphony::Database()->getInsertID();*/

			return $field_id;
		}

		private function __generateXML($fields)
		{
			if(!isset($fields['unique_hash']))
			{
				$fields['unique_hash'] = md5($fields['label'].time());
			}

			$configuration = '';
			// Get the available configuration fields from the field itself:
/*			$configuration_fields = FieldManager::create($fields['type'])->getConfiguration();
			if(!empty($configuration_fields))
			{
				foreach($configuration_fields as $configuration_field)
				{
					if(isset($fields[$configuration_field]))
					{
						$configuration .= '<'.$configuration_field.'>'.$fields[$configuration_field].'</'.$configuration_field.'>';
					}
				}
			}*/

			// Generate the field XML:
			$field_str = sprintf(
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
				$fields['element_name'],
				$fields['label'],
				$fields['unique_hash'],
				$fields['type'],
				(isset($fields['required']) ? $fields['required'] : 'no'),
				$fields['sortorder'],
				$fields['location'],
				$fields['show_column'],
				$configuration
			);
			$fieldXML = new SimpleXMLElement($field_str);

			// Store the field XML in the section:
			$section_hash = SectionManager::lookup()->getHash($fields['parent_section']);

			if(count(
				self::index()->xpath(
					sprintf('section[unique_hash=\'%s\']/fields', $section_hash)
				)) == 0)
			{
				// No fields found, create field element:
				$section = self::index()->xpath(sprintf('section[unique_hash=\'%s\']', $section_hash));
				$section[0]->addChild('fields');
			}

			// Reference to fields node:
			$root = self::index()->xpath(
				sprintf('section[unique_hash=\'%s\']/fields', $section_hash)
			);

			// Add the field:
			self::index()->mergeXML($root[0], $fieldXML);

			$sectionXML = self::index()->xpath(
				sprintf('section[unique_hash=\'%s\']', $section_hash)
			);

			// Save the new section XML file:
			$dom = new DOMDocument();
			$dom->preserveWhiteSpace = false;
			$dom->formatOutput = true;
			$dom->loadXML($sectionXML[0]->saveXML());

			// Save the XML:
			General::writeFile(
				WORKSPACE.'/sections/'.self::index()->xpath(
					sprintf('section[unique_hash=\'%s\']/name/@handle', $section_hash), true).'.xml',
				$dom->saveXML(),
				Symphony::Configuration()->get('write_mode', 'file')
			);

			// Re-index:
			// Todo: optimize the code with a save-function at the end?
			self::index()->reIndex();

			return $fields['unique_hash'];
		}

		/**
		 * Given a Field ID and associative array of fields, update an existing Field
		 * row in the `tbl_fields`table. Returns boolean for success/failure
		 *
		 * @param integer $id
		 *  The ID of the Field that should be updated
		 * @param array $fields
		 *  Associative array of field names => values for the Field object
		 *  This array does need to contain every value for the field object, it
		 *  can just be the changed values.
		 * @return boolean
		 */
		public static function edit($id, array $fields){
			// if(!Symphony::Database()->update($fields, "tbl_fields", " `id` = '$id'")) return false;

			$hash = self::lookup()->getHash($id);

			// Edit the index:
			foreach($fields as $key => $value)
			{
				if($key != 'handle')
				{
					self::index()->editValue(sprintf('section/fields/field[unique_hash=\'%s\']/%s', $hash, $key), $value);
				} else {
					self::index()->editAttribute(sprintf('section/fields/field[unique_hash=\'%s\']/label', $hash, $key), 'handle', $value);
				}
			}

			self::__saveField($hash);

			return true;
		}

		/**
		 * Add some custom options to the field
		 *
		 * @param $id
		 *  The ID of the field
		 * @param $data
		 *  An associated array with options
		 * @return bool
		 *  true on success, false on failure
		 */
		public static function addOptions($id, $data)
		{
			$hash = self::lookup()->getHash($id);
			$nodes = self::index()->xpath(sprintf('section/fields/field[unique_hash=\'%s\']', $hash));
			if(count($nodes) == 1)
			{
				$node = $nodes[0];
				// Field found, now add or edit the options:
				foreach($data as $key => $value)
				{
					$match = $node->xpath($key);
					if(!empty($match))
					{
						// Edit the node:
						self::index()->editValue(sprintf('section/fields/field[unique_hash=\'%s\']/%s', $hash, $key), $value);
					} else {
						// Add the node:
						$node->addChild($key, $value);
					}
				}
			}
			
			self::__saveField($hash);

			return true;
		}

		/**
		 * Save the field to the section XML file, according to it's hash
		 *
		 * @param $hash
		 *  The hash of the field
		 * @return void
		 */
		private static function __saveField($hash)
		{
			// Parent section hash:
			$section_hash = self::index()->xpath(
				sprintf('section[fields/field/unique_hash=\'%s\']/unique_hash', $hash), true
			);

			// Save the new XML:
			SectionManager::__saveXMLFile(
				self::index()->xpath(sprintf('section[unique_hash=\'%s\']/name/@handle', $section_hash), true),
				self::index()->getFormattedXML(sprintf('section[unique_hash=\'%s\']', $section_hash))
			);
		}

		/**
		 * Given a Field ID, delete a Field from Symphony. This will remove the field from
		 * the fields table, all of the data stored in this field's `tbl_entries_data_$id` any
		 * existing section associations. This function additionally call the Field's `tearDown`
		 * method so that it can cleanup any additional settings or entry tables it may of created.
		 *
		 * @param integer $id
		 *  The ID of the Field that should be deleted
		 * @return boolean
		 */
		public static function delete($id) {
			$existing = self::fetch($id);
			$existing->tearDown();

			// Symphony::Database()->delete('tbl_fields', " `id` = '$id'");
			// Symphony::Database()->delete('tbl_fields_'.$existing->handle(), " `field_id` = '$id'");

			$hash = self::lookup()->getHash($id);
			// Parent section hash, this needs to get retrieved before we remove the field.
			// That's also the reason why we won't use the __saveField()-function here:
			$section_hash = self::index()->xpath(
				sprintf('section[fields/field/unique_hash=\'%s\']/unique_hash', $hash), true
			);

			// Remove field node from index:
			self::index()->removeNode(sprintf('section/fields/field[unique_hash=\'%s\']', $hash));

			// Save the new XML:
			SectionManager::__saveXMLFile(
				self::index()->xpath(sprintf('section[unique_hash=\'%s\']/name/@handle', $section_hash), true),
				self::index()->getFormattedXML(sprintf('section[unique_hash=\'%s\']', $section_hash))
			);

			// Remove associations:
			SectionManager::removeSectionAssociation($id);

			// Remove entry data:
			Symphony::Database()->query('DROP TABLE `tbl_entries_data_'.$id.'`');

			return true;
		}

		/**
		 * @static
		 * @param string $xpath
		 *  A XPath expression to filter fields out of the Sections Index.
		 * @param string $order_by (optional)
		 *  Allows a developer to return the fields in a particular order. If omitted
		 *  this will return fields ordered by `sortorder`.
		 * @param string $order_direction (optional)
		 *  The direction to order (`asc` or `desc`)
		 *  Defaults to `asc`
		 * @return array
		 *  An array of Field objects. If no Field are found, null is returned.
		 */
		public static function fetchByXPath($xpath = 'section/fields/field', $order_by = 'sortorder', $order_direction = 'asc', $restrict=Field::__FIELD_ALL__) {
			$fieldNodes = self::index()->fetch($xpath, $order_by, $order_direction);

			$fields = array();
			$returnSingle = false;

			if($xpath == 'section/fields/field') {
				$returnSingle = true;
			}

			// Loop over the `$fieldNodes` and check to see we have
			// instances of the request fields
			foreach($fieldNodes as $fieldNode) {
				$key = 'f_'.(string)$fieldNode->unique_hash;
				if(
					isset(self::$_initialiased_fields[$key])
					&& self::$_initialiased_fields[$key] instanceof Field
				) {
					// Use cached object:
					$fields[self::lookup()->getId((string)$fieldNode->unique_hash)] = self::$_initialiased_fields[$key];
				} else {
					// Create new Field object:
					$field = self::create((string)$fieldNode->type);
					$data  = array();

					foreach($fieldNode->children() as $node)
					{
						$data[$node->getName()] = (string)$node[0];
						if($node->getName() == 'label')
						{
							$data['element_name'] = (string)$node['element_name'];
						}
					}
					$data['parent_section'] = self::index()->xpath(
						sprintf('section[fields/field/unique_hash=\'%s\']/unique_hash',
							(string)$fieldNode->unique_hash), true);
					$data['id'] = self::lookup()->getId((string)$fieldNode->unique_hash);
					// For backward compatibility (since field_id was added when reading tbl_fields_[xxx]):
					$data['field_id'] = $data['id'];

					$field->setArray($data);

/*					$field->setArray(array(
						'label'				=> (string)$fieldNode->label,
						'element_name'		=> (string)$fieldNode->label['element_name'],
						'type'				=> (string)$fieldNode->type,
						'parent_section'	=> self::index()->xpath(
							sprintf('section[fields/field/unique_hash=\'%s\']/unique_hash',
								(string)$fieldNode->unique_hash), true),
						'required'			=> (string)$fieldNode->required,
						'sortorder'			=> (string)$fieldNode->sortorder,
						'location'			=> (string)$fieldNode->location,
						'show_column'		=> (string)$fieldNode->show_column
					));*/

					// Get the context for this field from our previous queries.
/*					$context = $field_contexts[$f['type']][$f['id']];

					if (is_array($context) && !empty($context)) {
						try {
							unset($context['id']);
							$field->setArray($context);
						}

						catch (Exception $e) {
							throw new Exception(__(
								'Settings for field %s could not be found in table tbl_fields_%s.',
								array($f['id'], $f['type'])
							));
						}
					}*/

					self::$_initialiased_fields[$key] = $field;

					// Check to see if there was any restricts imposed on the fields
					if (
						$restrict == Field::__FIELD_ALL__
						|| ($restrict == Field::__TOGGLEABLE_ONLY__ && $field->canToggle())
						|| ($restrict == Field::__UNTOGGLEABLE_ONLY__ && !$field->canToggle())
						|| ($restrict == Field::__FILTERABLE_ONLY__ && $field->canFilter())
						|| ($restrict == Field::__UNFILTERABLE_ONLY__ && !$field->canFilter())
					) {
						$fields[self::lookup()->getId((string)$fieldNode->unique_hash)] = self::$_initialiased_fields[$key];
					}



					// Loop over the resultset building an array of type, field_id
/*					foreach($result as $f) {
						$ids[$f['type']][] = $f['id'];
					}

					// Loop over the `ids` array, which is grouped by field type
					// and get the field context.
					foreach($ids as $type => $field_id) {
						$field_contexts[$type] = Symphony::Database()->fetch(sprintf(
							"SELECT * FROM `tbl_fields_%s` WHERE `field_id` IN (%s)",
							$type, implode(',', $field_id)
						), 'field_id');
					}

					foreach($result as $f) {
						// We already have this field in our static store
						if(
							isset(self::$_initialiased_fields[$f['id']])
							&& self::$_initialiased_fields[$f['id']] instanceof Field
						) {
							$field = self::$_initialiased_fields[$f['id']];
						}
						// We don't have an instance of this field, so let's set one up
						else {
							$field = self::create($f['type']);
							$field->setArray($f);

							// Get the context for this field from our previous queries.
							$context = $field_contexts[$f['type']][$f['id']];

							if (is_array($context) && !empty($context)) {
								try {
									unset($context['id']);
									$field->setArray($context);
								}

								catch (Exception $e) {
									throw new Exception(__(
										'Settings for field %s could not be found in table tbl_fields_%s.',
										array($f['id'], $f['type'])
									));
								}
							}

							self::$_initialiased_fields[$f['id']] = $field;
						}

						// Check to see if there was any restricts imposed on the fields
						if (
							$restrict == Field::__FIELD_ALL__
							|| ($restrict == Field::__TOGGLEABLE_ONLY__ && $field->canToggle())
							|| ($restrict == Field::__UNTOGGLEABLE_ONLY__ && !$field->canToggle())
							|| ($restrict == Field::__FILTERABLE_ONLY__ && $field->canFilter())
							|| ($restrict == Field::__UNFILTERABLE_ONLY__ && !$field->canFilter())
						) {
							$fields[$f['id']] = $field;
						}
					}
*/
				}
			}


			return count($fields) <= 1 && $returnSingle ? current($fields) : $fields;
		}

		/**
		 * The fetch method returns a instance of a Field from tbl_fields. The most common
		 * use of this function is to retrieve a Field by ID, but it can be used to retrieve
		 * Fields from a Section also. There are several parameters that can be used to fetch
		 * fields by their Type, Location, by a Field Constant or with a custom WHERE query.
		 *
		 * @deprecated since 2.4
		 *
		 * @param integer|array $id
		 *  The ID of the field to retrieve. Defaults to null which will return multiple field
		 *  objects. Since Symphony 2.3, `$id` will accept an array of Field ID's
		 * @param integer $section_id
		 *  The ID of the section to look for the fields in. Defaults to null which will allow
		 *  all fields in the Symphony installation to be searched on.
		 * @param string $order
		 *  Available values of ASC (Ascending) or DESC (Descending), which refer to the
		 *  sort order for the query. Defaults to ASC (Ascending)
		 * @param string $sortfield
		 *  The field to sort the query by. Can be any from the tbl_fields schema. Defaults to
		 *  'sortorder'
		 * @param string $type
		 *  Filter fields by their type, ie. input, select. Defaults to null
		 * @param string $location
		 *  Filter fields by their location in the entry form. There are two possible values,
		 *  'main' or 'sidebar'. Defaults to null
		 * @param string $where
		 *  Allows a custom where query to be included. Must be valid SQL. The tbl_fields alias
		 *  is t1
		 * @param string $restrict
		 *  Only return fields if they match one of the Field Constants. Available values are
		 *  `__TOGGLEABLE_ONLY__`, `__UNTOGGLEABLE_ONLY__`, `__FILTERABLE_ONLY__`,
		 *  `__UNFILTERABLE_ONLY__` or `__FIELD_ALL__`. Defaults to `__FIELD_ALL__`
		 * @return array
		 *  An array of Field objects. If no Field are found, null is returned.
		 */
		public static function fetch($id = null, $section_id = null, $order = 'ASC', $sortfield = 'sortorder', $type = null, $location = null, $where = null, $restrict=Field::__FIELD_ALL__){
			if(!is_null($id)) {
				if(!is_array($id)) {
					$field_ids = array((int)$id);
				}
				else {
					$field_ids = $id;
				}
			} else {
				$field_ids = array();
			}

/*			$fields = array();
			$returnSingle = false;
			$ids = array();
			$field_contexts = array();

			if(!is_null($id)) {
				if(is_numeric($id)) {
					$returnSingle = true;
				}

				if(!is_array($id)) {
					$field_ids = array((int)$id);
				}
				else {
					$field_ids = $id;
				}

				// Loop over the `$field_ids` and check to see we have
				// instances of the request fields
				foreach($field_ids as $key => $field_id) {
					if(
						isset(self::$_initialiased_fields[$field_id])
						&& self::$_initialiased_fields[$field_id] instanceof Field
					) {
						$fields[$field_id] = self::$_initialiased_fields[$field_id];
						unset($field_ids[$key]);
					}
				}
			}*/

			// If there is any `$field_ids` left to be resolved lets do that, otherwise
			// if `$id` wasn't provided in the first place, we'll also continue
//			if(!empty($field_ids) || is_null($id)) {
/*				$sql = sprintf("
						SELECT t1.*
						FROM tbl_fields AS `t1`
						WHERE 1
						%s %s %s %s
						%s
					",
					isset($type) ? " AND t1.`type` = '{$type}' " : NULL,
					isset($location) ? " AND t1.`location` = '{$location}' " : NULL,
					isset($section_id) ? " AND t1.`parent_section` = '{$section_id}' " : NULL,
					$where,
					isset($field_ids) ? " AND t1.`id` IN(" . implode(',', $field_ids) . ") " : " ORDER BY t1.`{$sortfield}` {$order}"
				);*/

				// Create the XPath expression:
				$xpath = 'section';
				if(!empty($section_id)) {
					$xpath .= sprintf('[unique_hash=\'%s\']', SectionManager::lookup()->getHash($section_id));
				}
				$xpath.= '/fields/field';
				$expressions = array();
				if(!empty($type)) {
					$expressions[] = sprintf('type=\'%s\'', $type);
				}
				if(!empty($location)) {
					$expressions[] = sprintf('location=\'%s\'', $location);
				}
				if(!empty($field_ids)) {
					$fieldexpressions = array();
					foreach($field_ids as $field_id)
					{
						$fieldexpressions[] = sprintf('unique_hash=\'%s\'', self::lookup()->getHash($field_id));
					}
					$expressions[] = '('.implode(' or ', $fieldexpressions).')';
				}
				// Todo: Backward compatibility for $where-parameter

				if(!empty($expressions))
				{
					$xpath .= '['.implode(' and ', $expressions).']';
				}

				$fields = self::fetchByXPath($xpath,
					trim(strtolower($sortfield)),
					trim(strtolower($order)),
					$restrict
				);

				// For backward compatibility (return single):
				if(!is_null($id) && is_numeric($id) && is_array($fields) && count($fields) == 1) {
					return current($fields);
				} else {
					return $fields;
				}


				// return self::fetchByXPath($xpath);

				// if(!$result = Symphony::Database()->fetch($sql)) return ($returnSingle ? null : array());

/*				// Loop over the resultset building an array of type, field_id
				foreach($result as $f) {
					$ids[$f['type']][] = $f['id'];
				}

				// Loop over the `ids` array, which is grouped by field type
				// and get the field context.
				foreach($ids as $type => $field_id) {
					$field_contexts[$type] = Symphony::Database()->fetch(sprintf(
						"SELECT * FROM `tbl_fields_%s` WHERE `field_id` IN (%s)",
						$type, implode(',', $field_id)
					), 'field_id');
				}

				foreach($result as $f) {
					// We already have this field in our static store
					if(
						isset(self::$_initialiased_fields[$f['id']])
						&& self::$_initialiased_fields[$f['id']] instanceof Field
					) {
						$field = self::$_initialiased_fields[$f['id']];
					}
					// We don't have an instance of this field, so let's set one up
					else {
						$field = self::create($f['type']);
						$field->setArray($f);

						// Get the context for this field from our previous queries.
						$context = $field_contexts[$f['type']][$f['id']];

						if (is_array($context) && !empty($context)) {
							try {
								unset($context['id']);
								$field->setArray($context);
							}

							catch (Exception $e) {
								throw new Exception(__(
									'Settings for field %s could not be found in table tbl_fields_%s.',
									array($f['id'], $f['type'])
								));
							}
						}

						self::$_initialiased_fields[$f['id']] = $field;
					}

					// Check to see if there was any restricts imposed on the fields
					if (
						$restrict == Field::__FIELD_ALL__
						|| ($restrict == Field::__TOGGLEABLE_ONLY__ && $field->canToggle())
						|| ($restrict == Field::__UNTOGGLEABLE_ONLY__ && !$field->canToggle())
						|| ($restrict == Field::__FILTERABLE_ONLY__ && $field->canFilter())
						|| ($restrict == Field::__UNFILTERABLE_ONLY__ && !$field->canFilter())
					) {
						$fields[$f['id']] = $field;
					}
				}
			}

			return count($fields) <= 1 && $returnSingle ? current($fields) : $fields;*/
		}

		/**
		 * Given a field ID, return the type of the field by querying `tbl_fields`
		 *
		 * @param integer $id
		 * @return string
		 */
		public static function fetchFieldTypeFromID($id){
			$hash = self::lookup()->getHash($id);
			return self::index()->xpath(sprintf('sections/fields/field[unique_hash=\'%s\']/type', $hash), true);

			// return Symphony::Database()->fetchVar('type', 0, "SELECT `type` FROM `tbl_fields` WHERE `id` = '$id' LIMIT 1");
		}

		/**
		 * Given a field ID, return the handle of the field by querying `tbl_fields`
		 *
		 * @param integer $id
		 * @return string
		 */
		public static function fetchHandleFromID($id){
			$hash = self::lookup()->getHash($id);
			return self::index()->xpath(sprintf('sections/fields/field[unique_hash=\'%s\']/name/@element_name', $hash), true);

			// return Symphony::Database()->fetchVar('element_name', 0, "SELECT `element_name` FROM `tbl_fields` WHERE `id` = '$id' LIMIT 1");
		}

		/**
		 * Given an `$element_name` and a `$section_id`, return the Field ID. Symphony enforces
		 * a uniqueness constraint on a section where every field must have a unique
		 * label (and therefore handle) so whilst it is impossible to have two fields
		 * from the same section, it would be possible to have two fields with the same
		 * name from different sections. Passing the `$section_id` lets you to specify
		 * which section should be searched. If `$element_name` is null, this function will
		 * return all the Field ID's from the given `$section_id`.
		 *
		 * @since Symphony 2.3 This function can now accept $element_name as an array
		 *  of handles. These handles can now also include the handle's mode, eg. `title: formatted`
		 *
		 * @param string|array $element_name
		 *  The handle of the Field label, or an array of handles. These handles may contain
		 *  a mode as well, eg. `title: formatted`.
		 * @param integer $section_id
		 *  The section that this field belongs too
		 * @return mixed
		 *  The field ID, or an array of field ID's
		 */
		public static function fetchFieldIDFromElementName($element_name, $section_id = null){
			if(is_null($element_name)) {

				$xpath = sprintf('section[unique_hash=\'%s\']/fields/field',
					SectionManager::lookup()->getHash($section_id));

/*				$schema_sql = sprintf("
						SELECT `id`
						FROM `tbl_fields`
						WHERE `parent_section` = %d
						ORDER BY `sortorder` ASC
					",
					$section_id
				);*/
			}

			else {
				$element_names = !is_array($element_name) ? array($element_name) : $element_name;

				// allow for pseudo-fields containing colons (e.g. Textarea formatted/unformatted)
				foreach ($element_names as $index => $name) {
					$parts = explode(':', $name, 2);

					if(count($parts) == 1) continue;

					unset($element_names[$index]);

					// Prevent attempting to look up 'system', which will arise
					// from `system:pagination`, `system:id` etc.
					if($parts[0] == 'system') continue;

					// $element_names[] = Symphony::Database()->cleanValue(trim($parts[0]));
					$element_names[] = $parts[0];
				}

/*				$schema_sql = empty($element_names) ? null : sprintf("
						SELECT `id`
						FROM `tbl_fields`
						WHERE 1
						%s
						AND `element_name` IN ('%s')
						ORDER BY `sortorder` ASC
					",
					!is_null($section_id) ? sprintf("AND `parent_section` = %d", $section_id) : "",
					implode("', '", array_unique($element_names))
				);*/

				if(empty($element_names))
				{
					return false;
				} else {
					$xpath = 'section';
					if(!is_null($section_id))
					{
						$xpath .= '[unique_hash=\''.SectionManager::lookup()->getHash($section_id).'\']';
					}
					$xpath .= '/fields/field[unique_hash=\''.implode(
						'\' or unique_hash=\'', $element_names).'\']';
				}
			}

			// if(is_null($schema_sql)) return false;

			// $result = Symphony::Database()->fetch($schema_sql);
			$result = self::index()->xpath($xpath);

			// Todo: sorting?

			if(count($result) == 1) {
				return (int)self::lookup()->getId((string)$result[0]->unique_hash);
			}
			else if(empty($result)) {
				return false;
			}
			else {
				foreach($result as &$r) {
					// $r = (int)$r['id'];
					$r = (int)self::lookup()->getId((string)$r->unique_hash);
				}

				return $result;
			}
		}

		/**
		 * Work out the next available sort order for a new field
		 *
		 * @return integer
		 *  Returns the next sort order
		 */
		public static function fetchNextSortOrder(){
/*			$next = Symphony::Database()->fetchVar("next", 0, "
				SELECT
					MAX(p.sortorder) + 1 AS `next`
				FROM
					`tbl_fields` AS p
				LIMIT 1
			");
			return ($next ? (int)$next : 1);*/

			return count(self::index()->xpath('section/fields/field')) + 1;
		}

		/**
		 * Given a `$section_id`, this function returns an array of the installed
		 * fields schema. This includes the `id`, `element_name`, `type`
		 * and `location`.
		 *
		 * @since Symphony 2.3
		 * @param integer $section_id
		 * @return array
		 *  An associative array that contains four keys, `id`, `element_name`,
		 * `type` and `location`
		 */
		public static function fetchFieldsSchema($section_id) {
			$fields = self::index()->xpath(
				sprintf('section[unique_hash=\'%s\']/fields/field', (string)SectionManager::lookup()->getHash($section_id))
			);

			$schema = array();

			foreach($fields as $field)
			{
				$schema[] = array(
					'id' 			=> self::lookup()->getId((string)$field->unique_hash),
					'element_name'	=> (string)$field->label['element_name'],
					'type' 			=> (string)$field->type,
					'location'		=> (string)$field->location
				);
				// Todo: sortorder?
			}

			return $schema;

/*			return Symphony::Database()->fetch(sprintf("
					SELECT `id`, `element_name`, `type`, `location`
					FROM `tbl_fields`
					WHERE `parent_section` = %d
					ORDER BY `sortorder` ASC
				",
				$section_id
			));*/
		}

		/**
		 * Returns an array of all available field handles discovered in the
		 * `TOOLKIT . /fields` or `EXTENSIONS . /{}/fields`.
		 *
		 * @return array
		 *  A single dimensional array of field handles.
		 */
		public static function listAll() {
			$structure = General::listStructure(TOOLKIT . '/fields', '/field.[a-z0-9_-]+.php/i', false, 'asc', TOOLKIT . '/fields');

			$extensions = Symphony::ExtensionManager()->listInstalledHandles();

			if(is_array($extensions) && !empty($extensions)){
				foreach($extensions as $handle) {
					if(is_dir(EXTENSIONS . '/' . $handle . '/fields')){
						$tmp = General::listStructure(EXTENSIONS . '/' . $handle . '/fields', '/field.[a-z0-9_-]+.php/i', false, 'asc', EXTENSIONS . '/' . $handle . '/fields');
						if(is_array($tmp['filelist']) && !empty($tmp['filelist'])) {
							$structure['filelist'] = array_merge($structure['filelist'], $tmp['filelist']);
						}
					}
				}

				$structure['filelist'] = General::array_remove_duplicates($structure['filelist']);
			}

			$types = array();

			foreach($structure['filelist'] as $filename) {
				$types[] = self::__getHandleFromFilename($filename);
			}
			return $types;
		}

		/**
		 * Creates an instance of a given class and returns it. Adds the instance
		 * to the `$_pool` array with the key being the handle.
		 *
		 * @param string $type
		 *  The handle of the Field to create (which is it's handle)
		 * @return Field
		 */
		public static function create($type){
			if(!isset(self::$_pool[$type])){
				$classname = self::__getClassName($type);
				$path = self::__getDriverPath($type);

				if(!file_exists($path)){
					throw new Exception(
						__('Could not find Field %1$s at %2$s.', array('<code>' . $type . '</code>', '<code>' . $path . '</code>'))
						. ' ' . __('If it was provided by an Extension, ensure that it is installed, and enabled.')
					);
				}

				if(!class_exists($classname)){
					require_once($path);
				}

				self::$_pool[$type] = new $classname;

				if(self::$_pool[$type]->canShowTableColumn() && !self::$_pool[$type]->get('show_column')){
					self::$_pool[$type]->set('show_column', 'yes');
				}
			}

			return clone self::$_pool[$type];
		}

		/**
		 * Return boolean if the given `$field_type` is in use anywhere in the
		 * current Symphony install.
		 *
		 * @since Symphony 2.3
		 * @param string $field_type
		 * @return boolean
		 */
		public static function isFieldUsed($field_type) {
			return count(self::index()->xpath(
				sprintf('sections/fields/field[type=\'%s\']', $field_type)
			)) > 0;

/*			return Symphony::Database()->fetchVar('count', 0, sprintf("
				SELECT COUNT(*) AS `count` FROM `tbl_fields` WHERE `type` = '%s'
				", $field_type
			)) > 0;*/
		}

		/**
		 * Check if a specific text formatter is used by a Field
		 *
		 * @since Symphony 2.3
		 * @param $text_formatter_handle
		 *  The handle of the `TextFormatter`
		 * @return boolean
		 *  true if used, false if not
		 */
		public static function isTextFormatterUsed($text_formatter_handle) {
			// Assumes the name of the key is 'formatter':
			return count(self::index()->xpath(
				sprintf('sections/fields/field[formatter=\'%s\']', $text_formatter_handle)
			)) > 0;


/*			$fields = Symphony::Database()->fetchCol('type', "SELECT DISTINCT `type` FROM `tbl_fields` WHERE `type` NOT IN ('author', 'checkbox', 'date', 'input', 'select', 'taglist', 'upload')");
			if(!empty($fields)) foreach($fields as $field) {
				try {
					$table = Symphony::Database()->fetchVar('count', 0, sprintf("
						SELECT COUNT(*) AS `count`
						FROM `tbl_fields_%s`
						WHERE `formatter` = '%s'
					",
						Symphony::Database()->cleanValue($field),
						$text_formatter_handle
					));
				}
				catch (DatabaseException $ex) {
					// Table probably didn't have that column
				}

				if($table > 0) {
					return true;
				}
			}

			return false;*/
		}

		/**
		 * Returns an array of ID's of fields which are present in the section, but which not occur in the $id_list.
		 * @param $section_id
		 *  The ID of the section
		 * @param $id_list
		 *  An array with ID's of fields
		 * @return array
		 *  An array with ID's of fields
		 */
		public static function fetchRemovedFieldsFromSection($section_id, $id_list)
		{
			$xpath = sprintf('section[unique_hash=\'%s\']', SectionManager::lookup()->getHash($section_id));
			$xpath.= '/fields/field';
			if(!empty($id_list))
			{
				$xpath .= '[unique_hash!=\''.implode(
						'\' and unique_hash!=\'', $id_list).'\']';
			}
			$fields = self::index()->xpath($xpath);
			$ids    = array();

			foreach($fields as $field)
			{
				$ids[] = self::lookup()->getId((string)$field->unique_hash);
			}

			return $ids;

			// return Symphony::Database()->fetchCol('id', "SELECT `id` FROM `tbl_fields` WHERE `parent_section` = '$section_id' AND `id` NOT IN ('".@implode("', '", $id_list)."')");
		}

		/**
		 * Returns an array of all available field handles discovered in the
		 * `TOOLKIT . /fields` or `EXTENSIONS . /{}/fields`.
		 *
		 * @deprecated This function will be removed in Symphony 2.4. Use
		 * `FieldManager::listAll` instead.
		 * @return array
		 *  A single dimensional array of field handles.
		 */
		public static function fetchTypes() {
			return FieldManager::listAll();
		}
	}
