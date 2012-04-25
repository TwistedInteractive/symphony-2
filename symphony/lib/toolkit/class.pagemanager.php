<?php

	/**
	 * @package toolkit
	 */
	/**
	 * The `PageManager` class is responsible for providing basic CRUD operations
	 * for Symphony frontend pages. These pages are stored in seperate XML files in
	 * `workspace/pages` and are resolved to an instance of `FrontendPage` class from a URL.
	 * Additionally, this manager provides functions to access the Page's types,
	 * and any linked datasources or events.
	 *
	 * @since Symphony 2.3
	 */
	Class PageManager {

		/**
		 * Return the Index Object for Pages
		 *
		 * @return Index
		 */
		public static function index()
		{
			return Index::init(Index::INDEX_PAGES);
		}

		/**
		 * Return the Lookup Object for Pages
		 *
		 * @return Lookup
		 */
		public static function lookup()
		{
			return Lookup::init(Lookup::LOOKUP_PAGES);
		}

		/**
		 * Given an associative array of data, where the key is the name of the XML Element
		 * in `workspace/pages/[page].xml` and the value is the data, this function will create a new
		 * Page and return a Page ID on success.
		 *
		 * @param array $fields
		 *  Associative array of field names => values for the Page
		 * @return integer|boolean
		 *  Returns the Page ID of the created Page on success, false otherwise.
		 */
		public static function add(array $fields){
			if(!isset($fields['sortorder'])){
				$fields['sortorder'] = self::fetchNextSortOrder();
			}

			// Generate the pages' XML:
			$unique_hash = self::__generatePageXML($fields);
			
			// Store unique hash in the lookup table:
			$pageID = self::lookup()->save($unique_hash);

			return $pageID;
		}

		/**
		 * Generate the Page XML
		 *
		 * @param $fields
		 *  Associative array of fields names => values for the Page
		 * @return string
		 *  The unique hash of this page
		 */
		private function __generatePageXML($fields)
		{

			// Generate Page XML-file:
			// Generate a unique hash, this only happens the first time this page is created:
			if(!isset($fields['unique_hash']))
			{
				$fields['unique_hash'] = md5($fields['title'].time());
			}

			// For backward compatibility:
			if(isset($fields['data_sources'])) { $fields['datasources'] = $fields['data_sources']; }
			if(isset($fields['type'])) { $fields['types'] = $fields['type']; }

			// Generate datasources-xml:
			$datasources = '';
			if(!empty($fields['datasources']))
			{
				if(is_array($fields['datasources']))
				{
					$datasources = '<datasource>'.implode('</datasource><datasource>', $fields['datasources']).'</datasource>';
				} elseif(is_string($fields['datasources'])) {
					// For backward compatibility:
					$datasources = '<datasource>'.implode('</datasource><datasource>', explode(',', $fields['datasources'])) .'</datasource>';
				}
			}

			// Generate events-xml:
			$events = '';
			if(!empty($fields['events']))
			{
				if(is_array($fields['events']))
				{
					$events = '<event>'.implode('</event><event>', $fields['events']).'</event>';
				} elseif(is_string($fields['datasources'])) {
					// For backward compatibility:
					$events = '<event>'.implode('</event><event>', explode(',', $fields['events'])) .'</event>';
				}
			}

			// Generate types-xml:
			$types = '';
			if(!empty($fields['types']))
			{
				if(is_array($fields['types']))
				{
					$types = '<type>'.implode('</event><event>', $fields['types']).'</type>';
				} elseif(is_string($fields['types'])) {
					// For backward compatibility:
					$types = '<type>'.implode('</type><type>', explode(',', $fields['types'])) .'</type>';
				}
			}

			// Generate the main XML:
			$dom = new DOMDocument();
			$dom->preserveWhiteSpace = false;
			$dom->formatOutput = true;
			$dom->loadXML(sprintf('
				<page>
					<title handle="%1$s">%2$s</title>
					<unique_hash>%8$s</unique_hash>
					<parent>%10$s</parent>
					<path>%3$s</path>
					<params>%4$s</params>
					<datasources>%5$s</datasources>
					<events>%6$s</events>
					<types>%9$s</types>
					<sortorder>%7$d</sortorder>
				</page>
				',
				$fields['handle'],
				$fields['title'],
				$fields['path'],
				$fields['params'],
				$datasources,
				$events,
				$fields['sortorder'],
				$fields['unique_hash'],
			    $types,
			    (is_numeric($fields['parent']) ? self::lookup()->getHash($fields['parent']) : $fields['parent'])
			));

			// Save the XML:
			General::writeFile(
				self::resolvePageFileLocation($fields['path'], $fields['handle'], 'xml'),
				$dom->saveXML(),
				Symphony::Configuration()->get('write_mode', 'file')
			);

			// Re-index:
			// @Todo: optimize the code with a save-function at the end?
			self::index()->reIndex();

			return $fields['unique_hash'];
		}

        /**
         * This function checks if there are new pages added manually, or if there are pages deleted manually.
         * If so, an entry in the lookup table needs to be added or deleted:
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
                        sprintf(__('One or more pages are modified outside of Symphony. <a href="%s">Show differences</a>'),
                        SYMPHONY_URL.'/blueprints/pages/diff/'),
                        Alert::ERROR
                    );
                }
            }

/*            // First, check if there are duplicate unique hashes. This is ofcourse not done!
            if($_hash = self::index()->hasDuplicateHashes())
            {
                throw new Exception(__('Duplicate unique hash found in Pages: '.$_hash));
            }

            // Secondly check if there are hashes in our index that aren't present in the lookup table. This would mean
            // that a new page is added:
            $_pages = self::index()->fetch();
            foreach($_pages as $_page)
            {
                if(self::lookup()->getId((string)$_page->unique_hash) == false)
                {
                    // No ID found for this hash, this page is new!
                    self::lookup()->save((string)$_page->unique_hash);
                }
            }

            // Third, check if there are hashes in the lookup table that aren't used by any pages. This would mean
            // that a page is deleted:
            $_hashes = self::lookup()->getAllHashes();
            foreach($_hashes as $_hash)
            {
                if(self::index()->xpath('page[unique_hash=\''.$_hash.'\']', true) === false)
                {
                    // No page found with this hash, this page is deleted.
                    self::lookup()->delete($_hash);
                }
            }*/
        }

		/**
		 * Return a Page title by the handle
		 *
		 * @param string $handle
		 *  The handle of the page
		 * @return string
		 *  The Page title
		 */
		public static function fetchTitleFromHandle($handle){
			return (string)self::index()->xpath(sprintf('page/title[@handle=\'%s\']', $handle), true);
		}

		/**
		 * Return a Page ID by the handle
		 *
		 * @param string $handle
		 *  The handle of the page
		 * @return integer
		 *  The Page ID
		 */
		public static function fetchIDFromHandle($handle){
			$hash = (string)self::index()->xpath(sprintf('page[title/@handle=\'%s\']/unique_hash', $handle), true);
			return self::lookup()->getId($hash);
		}

		/**
		 * Given a Page ID and an array of types, this function will add Page types
		 * to that Page. Page types are stored in the XML `page/types`.
		 *
		 * @param integer $page_id
		 *  The Page ID to add the Types to
		 * @param array $types
		 *  An array of page types
		 * @return boolean
		 */
		public static function addPageTypesToPage($page_id = null, array $types) {
			if(is_null($page_id)) return false;

			PageManager::deletePageTypes($page_id);

			$_pages = self::fetchByXPath(
				sprintf('page[unique_hash=\'%s\']', self::lookup()->getHash($page_id))
			);

			$_page = $_pages[0];
			$_page['type'] = $types;

			self::__generatePageXML($_page);

			return true;
		}


		/**
		 * Returns the path to the page-template by looking at the
		 * `WORKSPACE/template/` directory, then at the `TEMPLATES`
		 * directory for `$name.xsl`. If the template is not found,
		 * false is returned
		 *
		 * @param string $name
		 *  Name of the template
		 * @return mixed
		 *  String, which is the path to the template if the template is found,
		 *  false otherwise
		 */
		public static function getTemplate($name) {
			$format = '%s/%s.xsl';
			if(file_exists($template = sprintf($format, WORKSPACE . '/template', $name)))
				return $template;
			elseif(file_exists($template = sprintf($format, TEMPLATE, $name)))
				return $template;
			else
				return false;
		}

		/**
		 * This function creates the initial `.xsl` template for the page, whether
		 * that be from the `TEMPLATES/blueprints.page.xsl` file, or from an existing
		 * template with the same name. This function will handle the renaming of a page
		 * by creating the new files using the old files as the templates then removing
		 * the old template. If a template already exists for a Page, it will not
		 * be overridden and the function will return true.
		 *
		 * @see toolkit.PageManager#resolvePageFileLocation()
		 * @see toolkit.PageManager#createHandle()
		 * @param string $new_path
		 *  The path of the Page, which is the handles of the Page parents. If the
		 *  page has multiple parents, they will be separated by a forward slash.
		 *  eg. article/read. If a page has no parents, this parameter should be null.
		 * @param string $new_handle
		 *  The new Page handle, generated using `PageManager::createHandle`.
		 * @param string $old_path (optional)
		 *  This parameter is only required when renaming a Page. It should be the 'old
		 *  path' before the Page was renamed.
		 * @param string $old_handle (optional)
		 *  This parameter is only required when renaming a Page. It should be the 'old
		 *  handle' before the Page was renamed.
		 * @return boolean
		 *  True when the page files have been created successfully, false otherwise.
		 */
		public static function createPageFiles($new_path, $new_handle, $old_path = null, $old_handle = null) {
			$new = PageManager::resolvePageFileLocation($new_path, $new_handle);
			$old = PageManager::resolvePageFileLocation($old_path, $old_handle);
			$oldConfig = PageManager::resolvePageFileLocation($old_path, $old_handle, 'xml');
			$data = null;

			// Nothing to do:
			if(file_exists($new) && $new == $old) return true;

			// Old file doesn't exist, use template:
			if(!file_exists($old)) {
				$data = file_get_contents(self::getTemplate('blueprints.page'));
			}
			else{
				$data = file_get_contents($old);
			}

			/**
			 * Just before a Page Template is about to be created & written to disk
			 *
			 * @delegate PageTemplatePreCreate
			 * @since Symphony 2.2.2
			 * @param string $context
			 * '/blueprints/pages/'
			 * @param string $file
			 *  The path to the Page Template file
			 * @param string $contents
			 *  The contents of the `$data`, passed by reference
			 */
			Symphony::ExtensionManager()->notifyMembers('PageTemplatePreCreate', '/blueprints/pages/', array('file' => $new, 'contents' => &$data));

			if(PageManager::writePageFiles($new, $data)) {
				// Remove the old file, in the case of a rename
				if(file_exists($old)) {
					General::deleteFile($old);
				}
				if(file_exists($oldConfig)) {
					General::deleteFile($oldConfig);
				}

				/**
				 * Just after a Page Template is saved after been created.
				 *
				 * @delegate PageTemplatePostCreate
				 * @since Symphony 2.2.2
				 * @param string $context
				 * '/blueprints/pages/'
				 * @param string $file
				 *  The path to the Page Template file
				 */
				Symphony::ExtensionManager()->notifyMembers('PageTemplatePostCreate', '/blueprints/pages/', array('file' => $new));

				return true;
			}

			return false;
		}

		/**
		 * A wrapper for `General::writeFile`, this function takes a `$path`
		 * and a `$data` and writes the new template to disk.
		 *
		 * @param string $path
		 *  The path to write the template to
		 * @param string $data
		 *  The contents of the template
		 * @return boolean
		 *  True when written successfully, false otherwise
		 */
		public static function writePageFiles($path, $data) {
			return General::writeFile($path, $data, Symphony::Configuration()->get('write_mode', 'file'));
		}

		/**
		 * This function will update a Page in `workspace/pages` given a `$page_id`
		 * and an associative array of `$fields`. A third parameter, `$delete_types`
		 * will also delete the Page's associated Page Types if passed true.
		 *
		 * @see toolkit.PageManager#addPageTypesToPage()
		 * @param integer $page_id
		 *  The ID of the Page that should be updated
		 * @param array $fields
		 *  Associative array of field names => values for the Page.
		 *  This array does need to contain every value for the Page, it
		 *  can just be the changed values.
		 * @param boolean $delete_types
		 *  If true, this parameter will cause the Page Types of the Page to
		 *  be deleted. By default this is false.
		 * @return boolean
		 */
		public static function edit($page_id, array $fields, $delete_types = false){
			if(!is_numeric($page_id)) return false;

			if(isset($fields['id'])) unset($fields['id']);

			// Load the original data:
			$_data = self::fetchByXPath(
				sprintf('page[unique_hash=\'%s\']', self::lookup()->getHash($page_id))
			);
			$_data = $_data[0];

			// Merge the arrays (that's really all that edit does...):
			foreach($fields as $key => $value)
			{
				$_data[$key] = $value;
			}

            if($delete_types) {
                $_data['type'] = array();
            }

            if(isset($fields['type']))
            {
                $_data['type'] = $fields['type'];
            }

			self::__generatePageXML($_data);

			return true;
		}

		/**
		 * This function will update all children of a particular page (if any)
		 * by renaming/moving all related files to their new path and updating
		 * their database information. This is a recursive function and will work
		 * to any depth.
		 *
		 * @param integer $page_id
		 *  The ID of the Page whose children need to be updated
		 * @param string $page_path
		 *  The path of the Page, which is the handles of the Page parents. If the
		 *  page has multiple parents, they will be separated by a forward slash.
		 *  eg. article/read. If a page has no parents, this parameter should be null.
		 * @return boolean
		 */
		public static function editPageChildren($page_id = null, $page_path = null) {
			if(!is_int($page_id)) return false;

			$page_path = trim($page_path, '/');
			$children = PageManager::fetchChildPages($page_id);

			$success = true;

			foreach ($children as $child) {
				$child_id = $child['id'];
				$fields = array(
					'path' => $page_path
				);

				if(!PageManager::createPageFiles($page_path, $child['handle'], $child['path'], $child['handle'])) {
					$success = false;
				}

				if(!PageManager::edit($child_id, $fields)) {
					$success = false;
				}

				self::editPageChildren($child_id, $page_path . '/' . $child['handle']);
			}

			return $success;
		}

		/**
		 * This function takes a Page ID and removes the Page and it's
		 * configuration from `workspace/pages`.
		 * This function does not delete any of the Page's children.
		 *
		 * @see toolkit.PageManager#deletePageTypes
		 * @see toolkit.PageManager#deletePageFiles
		 * @param integer $page_id
		 *  The ID of the Page that should be deleted.
		 * @param boolean $delete_files
		 *  If true, this parameter will remove the Page's templates from the
		 *  the filesystem. By default this is true.
		 * @return boolean
		 */
		public static function delete($page_id = null, $delete_files = true) {
			if(!is_int($page_id)) return false;
			$can_proceed = true;

			// Delete Files (if told to)
			if($delete_files) {
				$page = PageManager::fetchPageByID($page_id, array('path', 'handle'));

				if(empty($page)) return false;

				$can_proceed = PageManager::deletePageFiles($page['path'], $page['handle']);
			}

			if($can_proceed) {
				// Delete from lookup table:
				self::lookup()->delete($page_id);

			}

			return $can_proceed;
		}

		/**
		 * Given a `$page_id`, this function will remove all associated
		 * Page Types from the Pages' configuration XML.
		 *
		 * @param integer $page_id
		 *  The ID of the Page that should be deleted.
		 * @return boolean
		 */
		public static function deletePageTypes($page_id = null) {
			if(is_null($page_id)) return false;

			$_page = self::fetchPageByID($page_id);
			unset($_page['type']);
			self::__generatePageXML($_page);

			return true;
		}

		/**
		 * Given a Page's `$path` and `$handle`, this function will remove
		 * it's templates from the `PAGES` directory returning boolean on
		 * completion
		 *
		 * @param string $page_path
		 *  The path of the Page, which is the handles of the Page parents. If the
		 *  page has multiple parents, they will be separated by a forward slash.
		 *  eg. article/read. If a page has no parents, this parameter should be null.
		 * @param string $handle
		 *  A Page handle, generated using `PageManager::createHandle`.
		 * @return boolean
		 */
		public static function deletePageFiles($page_path, $handle) {
			$file = PageManager::resolvePageFileLocation($page_path, $handle);
			$configFile = PageManager::resolvePageFileLocation($page_path, $handle, 'xml');

			// Nothing to do:
			if(!file_exists($file) && !file_exists($configFile)) return true;

			// Delete it:
			if(General::deleteFile($file) && General::deleteFile($configFile)) return true;

			return false;
		}

		/**
		 * This function will return an associative array of Page information. The
		 * information returned is defined by the `$include_types` and `$select`
		 * parameters, which will return the Page Types for the Page and allow
		 * a developer to restrict what information is returned about the Page.
		 * Optionally, `$where` and `$order_by` parameters allow a developer to
		 * further refine their query. This function is here for backward compatibility.
		 * You should use the `fetchByXPath()`-function instead.
		 *
		 * @deprecated since 2.4
		 *
		 * @param boolean $include_types
		 *  Whether to include the resulting Page's Page Types in the return array,
		 *  under the key `type`. Defaults to true. Since 2.4, this parameter is no
		 *	longer used since a full data array is returned.
		 * @param array $select (optional)
		 *  Accepts an array of columns to return from `tbl_pages`. If omitted,
		 *  all columns from the table will be returned. Since 2.4, this parameter is no
		 *	longer used since a full data array is returned.
		 * @param array $where (optional)
		 *  Accepts an array of WHERE statements that will be appended with AND.
		 *  If omitted, all pages will be returned.
		 * @param string $order_by (optional)
		 *  Allows a developer to return the Pages in a particular order. The string
		 *  passed will be appended to `ORDER BY`. If omitted this will return
		 *  Pages ordered by `sortorder`.
		 * @param boolean $hierarchical (optional)
		 *  If true, builds a multidimensional array representing the pages hierarchy.
		 *  Defaults to false.
		 * @return array|null
		 *  An associative array of Page information with the key being the XML
		 *  element name and the value being the data. If requested, the array
		 *  can be made multidimensional to reflect the pages hierarchy. If no Pages are
		 *  found, null is returned.
		 */
		public static function fetch($include_types = true, array $select = array(), array $where = array(), $order_by = null, $hierarchical = false) {
			$_xpath = 'page';
			$_order_by = '';
			$_order_direction = '';
			if($order_by != null)
			{
				$a = explode(',', strtolower($order_by));
				// Only order by the first one (current limitation, but probably never really used)
				$a = explode(' ', trim($a[0]));
				if(count($a) == 2)
				{
					$_order_by = $a[0];
					$_order_direction = $a[1];
				} elseif(count($a) == 1)
				{
					$_order_by = $a[0];
					$_order_direction = 'asc';
				}
			}
			$_where = array();
			if(!empty($where))
			{
				// For now, convert MySQL to Lookup-actions (backward compatible):
				foreach($where as $action)
				{
					// for backward compatibility, try to convert the SQL statement to XPath:
					$a = explode(' ', $action);
					if(count($a == 3))
					{
						$a[0] = strtolower(str_replace(array('`', '"', '\''), '', $a[0]));
						$a[2] = strtolower(str_replace(array('`', '"', '\''), '', $a[2]));
						// Shortcut for ID:
						if($a[0] == 'id')
						{
							$_where['unique_hash'] = array(
								$a[1], "'".self::lookup()->getHash($a[2])."'"
							);
						} elseif(strtolower($a[1]) == 'regexp')
						{
							// Try to simulate the regexp for datasources and events:
							if($a[0] == 'data_sources') { $a[0] = 'datasources/datasource'; }
							if($a[0] == 'events') { $a[0] == 'events/event'; }
							$_where[$a[0]] = array('=', "'".str_replace(array('[[:<:]]', '[[:>:]]'), '', $a[2])."'");
						}
						else {
							// Default behavior:
							$_where[$a[0]] = array($a[1], "'".$a[2]."'");
						}
					} else {
						// For debugging now:
						// print_r($where).'<br />';
						// print_r($a);
					}
				}
			}
			if(!empty($_where))
			{
				$_xpath .= '['.implode(' and ', $_where).']';
			}
			return self::fetchByXPath($_xpath, $_order_by, $_order_direction, $hierarchical);
		}

		/**
		 * This function will return an associative array of Page information. The
		 * information returned is defined by the `$include_types` and `$select`
		 * parameters, which will return the Page Types for the Page and allow
		 * a developer to restrict what information is returned about the Page.
		 * Optionally, the `$xpath`, `$order_by` and `$order_direction` parameters
		 * allow a developer to further refine their query.
		 *
		 * @param string $xpath (optional)
		 *  A XPath expression to filter pages out of the Pages Index.
		 * @param string $order_by (optional)
		 *  Allows a developer to return the Pages in a particular order. If omitted
		 *  this will return pages ordered by `sortorder`.
		 * @param string $order_direction (optional
		 *  The direction to order (`asc` or `desc`)
		 *  Defaults to `asc`
		 * @param boolean $hierarchical (optional)
		 *  If true, builds a multidimensional array representing the pages hierarchy.
		 *  Defaults to false.
		 * @return array|null
		 *  An associative array of Page information with the key being the XML
		 *  element name and the value being the data. If requested, the array
		 *  can be made multidimensional to reflect the pages hierarchy. If no Pages are
		 *  found, null is returned.
		 */
		public static function fetchByXPath($xpath = 'page', $order_by = 'sortorder', $order_direction = 'asc', $hierarchical = false) {
			$_pages = self::index()->fetch($xpath, $order_by, $order_direction);
			
			// Convert array of SimpleXMLElements to associated array:
			$pages = array();
			foreach($_pages as $_page)
			{
				// Set the page ID:
				$page_id = self::lookup()->getId((string)$_page->unique_hash);

				// Set the datasources:
				$_datasources = array();
				foreach($_page->xpath('datasources/datasource') as $_datasource)
				{
					$_datasources[] = (string)$_datasource;
				}

				// Set the events:
				$_events = array();
				foreach($_page->xpath('events/event') as $_event)
				{
					$_events[] = (string)$_event;
				}

				// Set the page array:
				$page = array(
					'id'			=> $page_id,
					'parent' 		=> self::__getParentID($page_id),
					'title'  		=> (string)$_page->title,
					'handle' 		=> (string)$_page->title->attributes()->handle,
					'path'	 		=> (!empty($_page->path) ? (string)$_page->path : false),
					'params' 		=> (string)$_page->params,
					'data_sources' 	=> implode(',', $_datasources),
					'events' 		=> implode(',', $_events),
					'sortorder'		=> (string)$_page->sortorder,
					'unique_hash'	=> (string)$_page->unique_hash,
					'type'			=> PageManager::fetchPageTypes($page_id)
				);

				// Add the page to the pages array:
				$pages[] = $page;
			}

			if($hierarchical){
				$output = array();
				self::__buildTreeView(null, $pages, $output);
				$pages = $output;
			}

			return !empty($pages) ? $pages : array();
		}

		/**
		 * Recursive function to build a tree for the pages
		 *
		 * @param $parent_id
		 * @param $pages
		 * @param $results
		 * @return
		 */
		private function __buildTreeView($parent_id, $pages, &$results) {
			if (!is_array($pages)) return;

			foreach($pages as $page) {
				if ($page['parent'] == $parent_id) {
					$results[] = $page;

					self::__buildTreeView($page['id'], $pages, $results[count($results) - 1]['children']);
				}
			}
		}

		/**
		 * Get the parent ID of this page
		 *
		 * @param $page_id
		 *  The Page ID
		 * @return bool|int
		 *  The Parent ID if found, false otherwise
		 */
		private function __getParentID($page_id)
		{
			$_hash = self::lookup()->getHash($page_id);
			$_parent_hash = self::index()->xpath(
				sprintf('page[unique_hash = \'%s\']/parent', $_hash), true
			);
			if(!empty($_parent_hash))
			{
				return self::lookup()->getId($_parent_hash);
			} else {
				return false;
			}
		}

		/**
		 * Returns Pages that match the given `$page_id`. Developers can optionally
		 * choose to specify what Page information is returned using the `$select`
		 * parameter.
		 *
		 * @param integer|array $page_id
		 *  The ID of the Page, or an array of ID's
		 * @param array $select (optional)
		 *  Accepts an array of columns to return from `tbl_pages`. If omitted,
		 *  all columns from the table will be returned. Deprecated since 2.4
		 * @return array|null
		 *  An associative array of Page information with the key being the XML
		 *  element name and the value being the data. If multiple Pages
		 *  are found, an array of Pages will be returned. If no Pages are found
		 *  null is returned.
		 */
		public static function fetchPageByID($page_id = null, array $select = array()) {
			if(is_null($page_id)) return null;

			if(is_array($page_id)) $page_id = array_pop($page_id);

			$pages = self::fetchByXPath(
				sprintf('page[unique_hash=\'%s\']', self::lookup()->getHash($page_id))
			);

			return !empty($pages) ? $pages[0] : null;
		}

		/**
		 * Returns Pages that match the given `$type`. If no `$type` is provided
		 * the function returns the result of `PageManager::fetchByXPath`.
		 *
		 * @param string $type
		 *  Where the type is one of the available Page Types.
		 * @return array|null
		 *  An associative array of Page information with the key being the XML
		 *  element name and the value being the data. If multiple Pages
		 *  are found, an array of Pages will be returned. If no Pages are found
		 *  null is returned.
		 */
		public static function fetchPageByType($type = null) {
			if(is_null($type)) return self::fetchByXPath();

			$pages = self::fetchByXPath(
				sprintf('page[types/type = \'%s\']', $type)
			);

			return count($pages) == 1 ? array_pop($pages) : $pages;
		}

		/**
		 * Returns the child Pages (if any) of the given `$page_id`.
		 *
		 * @param integer $page_id
		 *  The ID of the Page.
		 * @param array $select (optional)
		 *  Accepts an array of columns to return from `tbl_pages`. If omitted,
		 *  all columns from the table will be returned. Deprecated since 2.4
		 * @return array|null
		 *  An associative array of Page information with the key being the XML
		 *  element name and the value being the data. If multiple Pages
		 *  are found, an array of Pages will be returned. If no Pages are found
		 *  null is returned.
		 */
		public static function fetchChildPages($page_id = null, array $select = array()) {
			if(is_null($page_id)) return null;

			return self::fetchByXPath(
				sprintf('page[unique_hash!=\'%1$s\' and parent=\'%1$s\']',
					self::lookup()->getHash($page_id))
			);
		}

		/**
		 * This function returns a Page's Page Types. If the `$page_id`
		 * parameter is given, the types returned will be for that Page.
		 *
		 * @param integer $page_id
		 *  The ID of the Page.
		 * @return array
		 *  An array of the Page Types
		 */
		public static function fetchPageTypes($page_id = null) {

			if($page_id != null)
			{
				$_hash 	= self::lookup()->getHash($page_id);
				$_types = self::index()->xpath(sprintf('page[unique_hash=\'%s\']/types/type', $_hash));
			} else {
				$_types = self::index()->xpath('page/types/type');
			}

			$_array = array();
            if($_types != false)
            {
                foreach($_types as $_type)
                {
                    $_array[] = (string)$_type;
                }
            }

			return $_array;
		}

		/**
		 * Returns all the page types that exist in this Symphony install.
		 * There are 6 default system page types, and new types can be added
		 * by Developers via the Page Editor.
		 *
		 * @since Symphony 2.3 introduced the JSON type.
		 * @return array
		 *  An array of strings of the page types used in this Symphony
		 *  install. At the minimum, this will be an array with the values
		 * 'index', 'XML', 'JSON', 'admin', '404' and '403'.
		 */
		public static function fetchAvailablePageTypes(){
			$system_types = array('index', 'XML', 'JSON', 'admin', '404', '403');

			$types = PageManager::fetchPageTypes();

			return !empty($types)
				? General::array_remove_duplicates(array_merge($system_types, $types))
				: $system_types;
		}

		/**
		 * Work out the next available sort order for a new page
		 *
		 * @return integer
		 *  Returns the next sort order
		 */
		public static function fetchNextSortOrder(){
			return self::index()->getMax('sortorder') + 1;
		}

		/**
		 * Fetch an associated array with Page ID's and the types they're using.
		 *
		 * @return array
		 *  A 2-dimensional associated array where the key is the page ID.
		 */
		public static function fetchAllPagesPageTypes() {
			$pages = self::fetchByXPath();

			$page_types = array();

			if(is_array($pages)) {
				foreach($pages as $page) {
					$page_types[$page['id']] = array();
					foreach($page['type'] as $type)
					{
						$page_types[$page['id']][] = $type;
					}
				}
			}

			return $page_types;
		}

		/**
		 * Given a name, this function will return a page handle. These handles
		 * will only contain latin characters
		 *
		 * @param string $name
		 *  The Page name to generate a handle for
		 * @return string
		 */
		public static function createHandle($name) {
			return Lang::createHandle($name, 255, '-', false, true, array(
				'@^[^a-z\d]+@i' => '',
				'/[^\w-\.]/i' => ''
			));
		}

		/**
		 * This function takes a `$path` and `$handle` and generates a flattened
		 * string for use as a filename for a Page's template.
		 *
		 * @param string $path
		 *  The path of the Page, which is the handles of the Page parents. If the
		 *  page has multiple parents, they will be separated by a forward slash.
		 *  eg. article/read. If a page has no parents, this parameter should be null.
		 * @param string $handle
		 *  A Page handle, generated using `PageManager::createHandle`.
		 * @return string
		 */
		public static function createFilePath($path, $handle) {
			return trim(str_replace('/', '_', $path . '_' . $handle), '_');
		}

		/**
		 * This function will return the number of child pages for a given
		 * `$page_id`. This is a recursive function and will return the absolute
		 * count.
		 *
		 * @param integer $page_id
		 *  The ID of the Page.
		 * @return integer
		 *  The number of child pages for the given `$page_id`
		 */
		public static function getChildPagesCount($page_id = null) {
			if(is_null($page_id)) return null;

			$children = self::fetchByXPath(
				sprintf('page[parent=\'%s\']', self::lookup()->getHash($page_id))
			);

			$count = count($children);

			if($count > 0){
				foreach($children as $c){
					$count += self::getChildPagesCount($c['id']);
				}
			}

			return $count;
		}

		/**
		 * Returns boolean if a the given `$type` has been used by Symphony
		 * for a Page that is not `$page_id`.
		 *
		 * @param integer $page_id
		 *  The ID of the Page to exclude from the query.
		 * @param string $type
		 *  The Page Type to look for.
		 * @return boolean
		 *  True if the type is used, false otherwise
		 */
		public static function hasPageTypeBeenUsed($page_id = null, $type) {
			$xpath = 'page[types/type = \''.$type.'\'';
			if($page_id != null) {
				$hash  = self::lookup()->getHash($page_id);
				$xpath.= ' and unique_hash != \''.$hash.'\'';
			}
			$xpath.= ']';
			return count(self::index()->xpath($xpath)) > 0;
		}

		/**
		 * Given a `$page_id`, this function returns boolean if the page
		 * has child pages.
		 *
		 * @param integer $page_id
		 *  The ID of the Page to check
		 * @return boolean
		 *  True if the page has children, false otherwise
		 */
		public static function hasChildPages($page_id = null) {

			$_hash = self::lookup()->getHash($page_id);
			$_children = self::index()->xpath(
				sprintf('page[parent=\'%s\']', $_hash)
			);
			return count($_children) > 0;
		}

		/**
		 * Resolves the path to this page's XSLT file. The Symphony convention
		 * is that they are stored in the `PAGES` folder. If this page has a parent
		 * it will be as if all the / in the URL have been replaced with _. ie.
		 * /articles/read/ will produce a file `articles_read.xsl`
		 *
		 * @see toolkit.PageManager#createFilePath()
		 * @param string $path
		 *  The URL path to this page, excluding the current page. ie, /articles/read
		 *  would make `$path` become articles/
		 * @param string $handle
		 *  The handle of the page.
		 * @param string $extension
		 *  The extension of the file.
		 * @return string
		 *  The path to the XSLT of the page
		 */
		public static function resolvePageFileLocation($path, $handle, $extension = 'xsl'){
			return PAGES . '/' . PageManager::createFilePath($path, $handle) . '.' . $extension;
		}

		/**
		 * Given the `$page_id` and a `$column`, this function will return an
		 * array of the given `$column` for the Page, including all parents.
		 *
		 * @param mixed $page_id
		 *  The ID of the Page that currently being viewed, or the handle of the
		 *  current Page
		 * @param string $column
		 *  The name of the column (title, handle, etc.)
		 * @return array
		 *  An array of the current Page, containing the `$column`
		 *  requested. The current page will be the last item the array, as all
		 *  parent pages are prepended to the start of the array
		 */
		public static function resolvePage($page_id, $column) {
			if(is_numeric($page_id))
			{
				$pages = self::fetchByXPath(
					sprintf('page[unique_hash=\'%s\']', self::lookup()->getHash($page_id))
				);
			} else {
				$pages = self::fetchByXPath(
					sprintf('page[title/@handle=\'%s\']', $page_id)
				);
			}
			$page = $pages[0];


			if(empty($page)) return $page;

			$path = array($page[$column]);

			if (!is_null($page['parent'])) {
				$next_parent = $page['parent'];

				$_continue = true;

				while ($_continue) {
					$_page = self::fetchByXPath(
						sprintf('page[unique_hash=\'%s\']', self::lookup()->getHash($next_parent))
					);
					if(!empty($_page))
					{
						array_unshift($path, $_page[0][$column]);
						$next_parent = $_page['parent'];
					} else {
						$_continue = false;
					}
				}
			}

			return $path;
		}

		/**
		 * Given the `$page_id`, return the complete title of the
		 * current page. Each part of the Page's title will be
		 * separated by ': '.
		 *
		 * @param mixed $page_id
		 *  The ID of the Page that currently being viewed, or the handle of the
		 *  current Page
		 * @return string
		 *  The title of the current Page. If the page is a child of another
		 *  it will be prepended by the parent and a colon, ie. Articles: Read
		 */
		public static function resolvePageTitle($page_id) {
			$path = PageManager::resolvePage($page_id, 'title');

			return implode(': ', $path);
		}

		/**
		 * Given the `$page_id`, return the complete path to the
		 * current page. Each part of the Page's path will be
		 * separated by '/'.
		 *
		 * @param mixed $page_id
		 *  The ID of the Page that currently being viewed, or the handle of the
		 *  current Page
		 * @return string
		 *  The complete path to the current Page including any parent
		 *  Pages, ie. /articles/read
		 */
		public static function resolvePagePath($page_id) {
			$path = PageManager::resolvePage($page_id, 'handle');

			return implode('/', $path);
		}

        /**
         * Check whether a datasource is used or not
         *
         * @param string $handle
         *  The datasource handle
         * @return bool
         *  True if used, false if not
         */
        public static function isDataSourceUsed($handle)
        {
            $_page = self::index()->xpath(
				sprintf('page[datasources/datasource=\'%s\'][1]', $handle), true
			);
			return $_page != false;
        }

        /**
         * Check whether a event is used or not
         *
         * @param string $handle
         *  The event handle
         * @return bool
         *  True if used, false if not
         */
        public static function isEventUsed($handle)
        {
			$_page = self::index()->xpath(
				sprintf('page[events/event=\'%s\'][1]', $handle), true
			);
			return $_page != false;
        }

        /**
         * Resolve a page by it's handle and path
         *
         * @param $handle
         *  The handle of the page
         * @param bool $path
         *  The path to the page
         * @return mixed
         *  Array if found, false if not
         */
        public static function resolvePageByPath($handle, $path = false)
        {
			$xpath = 'page[title/@handle=\''.$handle.'\'';
			if($path != false) { $xpath .= ' and path=\''.$path.'\''; }
			$xpath .= ']';
			$pages = self::fetchByXPath($xpath);
			if(count($pages) > 0)
			{
				return $pages[0];
			} else {
				return false;
			}
        }

        /**
         * Fetch an associated array with Page ID's and the types they're using.
         *
         * @return array
         *  A 2-dimensional associated array where the key is the page ID.
         */
        public static function fetchPageTypeArray()
        {
			$page_types = array();
			$_pages = self::fetchByXPath();

			foreach($_pages as $_page)
			{
				$page_types[$_page['id']] = $_page['type'];
			}

            return $page_types;
        }
	}
