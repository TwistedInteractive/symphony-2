<?php

	/**
	 * @package content
	 */

	/**
	 * Developers can create new Frontend pages from this class. It provides
	 * an index view of all the pages in this Symphony install as well as the
	 * forms for the creation/editing of a Page
	 */
	require_once(TOOLKIT . '/class.administrationpage.php');
	require_once(TOOLKIT . '/class.resourcemanager.php');
	require_once(TOOLKIT . '/class.xsltprocess.php');

	class contentBlueprintsPages extends AdministrationPage {

		public $_errors = array();
		protected $_hilights = array();

		public function insertBreadcrumbsUsingPageIdentifier($page_id, $preserve_last = true) {
			if($page_id == 0) {
				return parent::insertBreadcrumbs(
					array(Widget::Anchor(__('Pages'), SYMPHONY_URL . '/blueprints/pages/'))
				);
			}

			$pages = PageManager::resolvePage($page_id, 'handle');

			foreach($pages as &$page){
				// If we are viewing the Page Editor, the Breadcrumbs should link
				// to the parent's Page Editor.
				if($this->_context[0] == 'edit') {
					$page = Widget::Anchor(
						PageManager::fetchTitleFromHandle($page),
						SYMPHONY_URL . '/blueprints/pages/edit/' . PageManager::fetchIDFromHandle($page) . '/'
					);
				}

				// If the pages index is nested, the Breadcrumb should link to the
				// Pages Index filtered by parent
				else if(Symphony::Configuration()->get('pages_table_nest_children', 'symphony') == 'yes') {
					$page = Widget::Anchor(
						PageManager::fetchTitleFromHandle($page),
						SYMPHONY_URL . '/blueprints/pages/?parent=' . PageManager::fetchIDFromHandle($page)
					);
				}

				// If there is no nesting on the Pages Index, the breadcrumb is
				// not a link, just plain text
				else {
					$page = new XMLElement('span', PageManager::fetchTitleFromHandle($page));
				}
			}

			if(!$preserve_last) array_pop($pages);

			parent::insertBreadcrumbs(array_merge(
				array(Widget::Anchor(__('Pages'), SYMPHONY_URL . '/blueprints/pages/')),
				$pages
			));
		}

		public function listAllPages($separator = '/') {
			// $pages = PageManager::fetch(false, array('id', 'handle', 'title', 'path'));
			$pages = PageManager::fetchByXPath();

			foreach($pages as &$page){
				$parents = explode('/', $page['path']);

				foreach($parents as &$parent){
					$parent = PageManager::fetchTitleFromHandle($parent);
				}

				$parents = implode($separator, $parents);
				$page['title'] = ($parents ? $parents . $separator . $page['title'] : $page['title']);
			}

			return $pages;
		}

		public function __viewIndex() {
			$this->setPageType('table');
			$this->setTitle(__('%1$s &ndash; %2$s', array(__('Pages'), __('Symphony'))));

			$nesting = (Symphony::Configuration()->get('pages_table_nest_children', 'symphony') == 'yes');

			if($nesting == true && isset($_GET['parent']) && is_numeric($_GET['parent'])) {
				$parent = PageManager::fetchPageByID((int)$_GET['parent'], array('title', 'id'));
			}

			$this->appendSubheading(isset($parent) ? $parent['title'] : __('Pages'), Widget::Anchor(
				__('Create New'), Administration::instance()->getCurrentPageURL() . 'new/' . ($nesting == true && isset($parent) ? "?parent={$parent['id']}" : NULL),
				__('Create a new page'), 'create button', NULL, array('accesskey' => 'c')
			));

			if(isset($parent)) {
				$this->insertBreadcrumbsUsingPageIdentifier($parent['id'], false);
			}

			$aTableHead = array(
				array(__('Title'), 'col'),
				array(__('Template'), 'col'),
				array('<abbr title="' . __('Universal Resource Locator') . '">' . __('URL') . '</abbr>', 'col'),
				array('<abbr title="' . __('Universal Resource Locator') . '">' . __('URL') . '</abbr> ' . __('Parameters'), 'col'),
				array(__('Type'), 'col')
			);
			$aTableBody = array();

			$xpath = 'page';

			if($nesting == true){
				$aTableHead[] = array(__('Children'), 'col');
/*				$where = array(
					'parent ' . (isset($parent) ? " = {$parent['id']} " : ' IS NULL ')
				);*/
				if(isset($parent))
				{
					$xpath = sprintf('page[parent=\'%s\']', PageManager::lookup()->getHash($parent['id']));
				}
			}
			else {
				// $where = array();
			}

			// $pages = PageManager::fetch(true, array('*'), $where);
			$pages = PageManager::fetchByXPath($xpath);

			if(!is_array($pages) or empty($pages)) {
				$aTableBody = array(Widget::TableRow(array(
					Widget::TableData(__('None found.'), 'inactive', null, count($aTableHead))
				), 'odd'));

			}
			else{
				foreach ($pages as $page) {
					$class = array();

					$page_title = ($nesting == true ? $page['title'] : PageManager::resolvePageTitle($page['id']));
					$page_url = URL . '/' . PageManager::resolvePagePath($page['id']) . '/';
					$page_edit_url = Administration::instance()->getCurrentPageURL() . 'edit/' . $page['id'] . '/';
					$page_template = PageManager::createFilePath($page['path'], $page['handle']);
					$page_template_url = Administration::instance()->getCurrentPageURL() . 'template/' . $page_template . '/';

					$col_title = Widget::TableData(Widget::Anchor(
						$page_title, $page_edit_url, $page['handle']
					));
					$col_title->appendChild(Widget::Input("items[{$page['id']}]", null, 'checkbox'));

					$col_template = Widget::TableData(Widget::Anchor(
						$page_template . '.xsl',
						$page_template_url
					));

					$col_url = Widget::TableData(Widget::Anchor($page_url, $page_url));

					if($page['params']) {
						$col_params = Widget::TableData(trim($page['params'], '/'));

					} else {
						$col_params = Widget::TableData(__('None'), 'inactive');
					}

					if(!empty($page['type'])) {
						$col_types = Widget::TableData(implode(', ', $page['type']));

					} else {
						$col_types = Widget::TableData(__('None'), 'inactive');
					}

					if(in_array($page['id'], $this->_hilights)) $class[] = 'failed';

					$columns = array($col_title, $col_template, $col_url, $col_params, $col_types);

					if($nesting == true){
						if(PageManager::hasChildPages($page['id'])){
							$col_children = Widget::TableData(
								Widget::Anchor(PageManager::getChildPagesCount($page['id']) . ' &rarr;',
								SYMPHONY_URL . '/blueprints/pages/?parent=' . $page['id'])
							);
						}
						else{
							$col_children = Widget::TableData(__('None'), 'inactive');
						}

						$columns[] = $col_children;
					}

					$aTableBody[] = Widget::TableRow(
						$columns,
						implode(' ', $class)
					);
				}
			}

			$table = Widget::Table(
				Widget::TableHead($aTableHead), null,
				Widget::TableBody($aTableBody), 'orderable selectable'
			);

			$this->Form->appendChild($table);

			$tableActions = new XMLElement('div');
			$tableActions->setAttribute('class', 'actions');

			$options = array(
				array(null, false, __('With Selected...')),
				array('delete', false, __('Delete'), 'confirm', null, array(
					'data-message' => __('Are you sure you want to delete the selected pages?')
				))
			);

			$tableActions->appendChild(Widget::Apply($options));
			$this->Form->appendChild($tableActions);
		}

		public function __viewTemplate() {
			$this->setPageType('form');
			$this->Form->setAttribute('action', SYMPHONY_URL . '/blueprints/pages/template/' . $this->_context[1] . '/');
			$this->Form->setAttribute('class', 'columns');

			$filename = $this->_context[1] . '.xsl';
			$file_abs = PAGES . '/' . $filename;

			$is_child = strrpos($this->_context[1],'_');
			$pagename = ($is_child != false ? substr($this->_context[1], $is_child + 1) : $this->_context[1]);
/*			$pagedata = PageManager::fetch(false, array('id'), array(
				"p.handle = '{$pagename}'"
			));*/

			$pagedata = PageManager::fetchByXPath(sprintf('page[title/@handle=\'%s\']', $pagename));
			
			$pagedata = array_pop($pagedata);

			if(!is_file($file_abs)) redirect(SYMPHONY_URL . '/blueprints/pages/');

			$fields['body'] = @file_get_contents($file_abs);

			$formHasErrors = (is_array($this->_errors) && !empty($this->_errors));
			if($formHasErrors) {
				$this->pageAlert(
					__('An error occurred while processing this form. See below for details.')
					, Alert::ERROR
				);
			}
			// These alerts are only valid if the form doesn't have errors
			else if(isset($this->_context[2])) {
				$this->pageAlert(
					__('Page updated at %s.', array(DateTimeObj::getTimeAgo()))
					. ' <a href="' . SYMPHONY_URL . '/blueprints/pages/new/" accesskey="c">'
					. __('Create another?')
					. '</a> <a href="' . SYMPHONY_URL . '/blueprints/pages/" accesskey="a">'
					. __('View all Pages')
					. '</a>'
					, Alert::SUCCESS);
			}

			$this->setTitle(__(
				($filename ? '%1$s &ndash; %2$s &ndash; %3$s' : '%2$s &ndash; %3$s'),
				array(
					$filename,
					__('Pages'),
					__('Symphony')
				)
			));

			$this->appendSubheading(__($filename ? $filename : __('Untitled')), Widget::Anchor(__('Edit Page'), SYMPHONY_URL . '/blueprints/pages/edit/' . $pagedata['id'] . '/', __('Edit Page Configuration'), 'button', NULL, array('accesskey' => 't')));
			$this->insertBreadcrumbsUsingPageIdentifier($pagedata['id']);

			if(!empty($_POST)) $fields = $_POST['fields'];

			$fields['body'] = htmlentities($fields['body'], ENT_COMPAT, 'UTF-8');

			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'primary column');

			$label = Widget::Label(__('Body'));
			$label->appendChild(Widget::Textarea(
				'fields[body]', 30, 80, $fields['body'],
				array(
					'class' => 'code'
				)
			));

			if(isset($this->_errors['body'])) {
				$label = Widget::Error($label, $this->_errors['body']);
			}

			$fieldset->appendChild($label);
			$this->Form->appendChild($fieldset);

			$utilities = General::listStructure(UTILITIES, array('xsl'), false, 'asc', UTILITIES);
			$utilities = $utilities['filelist'];

			if(is_array($utilities) && !empty($utilities)) {
				$this->Form->setAttribute('class', 'two columns');

				$div = new XMLElement('div');
				$div->setAttribute('class', 'secondary column');

				$p = new XMLElement('p', __('Utilities'));
				$p->setAttribute('class', 'label');
				$div->appendChild($p);

				$frame = new XMLElement('div', null, array('class' => 'frame'));

				$ul = new XMLElement('ul');
				$ul->setAttribute('id', 'utilities');

				foreach ($utilities as $util) {
					$li = new XMLElement('li');
					$li->appendChild(Widget::Anchor($util, SYMPHONY_URL . '/blueprints/utilities/edit/' . str_replace('.xsl', '', $util) . '/', NULL));
					$ul->appendChild($li);
				}

				$frame->appendChild($ul);
				$div->appendChild($frame);
				$this->Form->appendChild($div);
			}

			$div = new XMLElement('div');
			$div->setAttribute('class', 'actions');
			$div->appendChild(Widget::Input(
				'action[save]', __('Save Changes'),
				'submit', array('accesskey' => 's')
			));

			$this->Form->appendChild($div);
		}

		public function __viewNew() {
			$this->__viewEdit();
		}

		public function __viewEdit() {
			$this->setPageType('form');
			$fields = array();

			$nesting = (Symphony::Configuration()->get('pages_table_nest_children', 'symphony') == 'yes');

			// Verify page exists:
			if($this->_context[0] == 'edit') {
				if(!$page_id = $this->_context[1]) {
					redirect(SYMPHONY_URL . '/blueprints/pages/');
				}

				$existing = PageManager::fetchPageByID($page_id);

				if(!$existing) {
					Administration::instance()->errorPageNotFound();
				}
				else {
					$existing['type'] = PageManager::fetchPageTypes($page_id);
				}
			}

			// Status message:
			if(isset($this->_context[2])){
				$flag = $this->_context[2];
				$link_suffix = '';

				if(isset($_REQUEST['parent']) && is_numeric($_REQUEST['parent'])){
					$link_suffix = "?parent=" . $_REQUEST['parent'];
				}

				else if($nesting == true && isset($existing) && !is_null($existing['parent'])){
					$link_suffix = '?parent=' . $existing['parent'];
				}

				switch($flag){

					case 'saved':
						$this->pageAlert(
							__('Page updated at %s.', array(DateTimeObj::getTimeAgo()))
							. ' <a href="' . SYMPHONY_URL . '/blueprints/pages/new/" accesskey="c">'
							. __('Create another?')
							. '</a> <a href="' . SYMPHONY_URL . '/blueprints/pages/" accesskey="a">'
							. __('View all Pages')
							. '</a>'
							, Alert::SUCCESS);

						break;

					case 'created':
						$this->pageAlert(
							__('Page created at %s.', array(DateTimeObj::getTimeAgo()))
							. ' <a href="' . SYMPHONY_URL . '/blueprints/pages/new/" accesskey="c">'
							. __('Create another?')
							. '</a> <a href="' . SYMPHONY_URL . '/blueprints/pages/" accesskey="a">'
							. __('View all Pages')
							. '</a>'
							, Alert::SUCCESS);

				}
			}

			// Find values:
			if(isset($_POST['fields'])) {
				$fields = $_POST['fields'];
			}

			elseif($this->_context[0] == 'edit') {
				$fields = $existing;

				if(!is_null($fields['type'])) {
					$fields['type'] = implode(', ', $fields['type']);
				}

				$fields['data_sources'] = preg_split('/,/i', $fields['data_sources'], -1, PREG_SPLIT_NO_EMPTY);
				$fields['events'] = preg_split('/,/i', $fields['events'], -1, PREG_SPLIT_NO_EMPTY);
			}

			elseif(isset($_REQUEST['parent']) && is_numeric($_REQUEST['parent'])){
				$fields['parent'] = $_REQUEST['parent'];
			}

			$title = $fields['title'];
			if(trim($title) == '') $title = $existing['title'];

			$this->setTitle(__(
				($title ? '%1$s &ndash; %2$s &ndash; %3$s' : '%2$s &ndash; %3$s'),
				array(
					$title,
					__('Pages'),
					__('Symphony')
				)
			));

			if($existing) {
				$template_name = $fields['handle'];
				if($existing['parent']){
					$parents = PageManager::resolvePagePath($existing['parent']);
					$template_name = PageManager::createFilePath($parents, $fields['handle']);
				}
				$this->appendSubheading(__($title ? $title : __('Untitled')), Widget::Anchor(__('Edit Template'), SYMPHONY_URL . '/blueprints/pages/template/' . $template_name, __('Edit Page Template'), 'button', NULL, array('accesskey' => 't')));
			}
			else {
				$this->appendSubheading(($title ? $title : __('Untitled')));
			}

			if(isset($this->_context[1])) {
				$this->insertBreadcrumbsUsingPageIdentifier($this->_context[1], false);
			}
			else {
				$this->insertBreadcrumbsUsingPageIdentifier((int)$_GET['parent'], true);
			}

		// Title --------------------------------------------------------------

			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Page Settings')));

			$label = Widget::Label(__('Title'));
			$label->appendChild(Widget::Input(
				'fields[title]', General::sanitize($fields['title'])
			));

			if(isset($this->_errors['title'])) {
				$label = Widget::Error($label, $this->_errors['title']);
			}

			$fieldset->appendChild($label);

		// Handle -------------------------------------------------------------

			$group = new XMLElement('div');
			$group->setAttribute('class', 'two columns');
			$column = new XMLElement('div');
			$column->setAttribute('class', 'column');

			$label = Widget::Label(__('URL Handle'));
			$label->appendChild(Widget::Input(
				'fields[handle]', $fields['handle']
			));

			if(isset($this->_errors['handle'])) {
				$label = Widget::Error($label, $this->_errors['handle']);
			}

			$column->appendChild($label);

		// Parent ---------------------------------------------------------

			$label = Widget::Label(__('Parent Page'));

/*			$where = array(
				sprintf('id != %d', $page_id)
			);*/
			// $pages = PageManager::fetch(false, array('id'), $where, 'title ASC');
			$pages = PageManager::fetchByXPath(
				sprintf('page[unique_hash!=\'%s\']', PageManager::lookup()->getHash($page_id))
			);

			$options = array(
				array('', false, '/')
			);

			if(!empty($pages)) {
				if(!function_exists('__compare_pages')) {
					function __compare_pages($a, $b) {
						return strnatcasecmp($a[2], $b[2]);
					}
				}

				foreach ($pages as $page) {
					$options[] = array(
						$page['id'], $fields['parent'] == $page['id'],
						'/' . PageManager::resolvePagePath($page['id'])
					);
				}

				usort($options, '__compare_pages');
			}

			$label->appendChild(Widget::Select(
				'fields[parent]', $options
			));
			$column->appendChild($label);
			$group->appendChild($column);

		// Parameters ---------------------------------------------------------

			$column = new XMLElement('div');
			$column->setAttribute('class', 'column');

			$label = Widget::Label(__('URL Parameters'));
			$label->appendChild(Widget::Input(
				'fields[params]', $fields['params'], 'text', array('placeholder' => 'param1/param2')
			));
			$column->appendChild($label);

		// Type -----------------------------------------------------------

			$label = Widget::Label(__('Page Type'));
			$label->appendChild(Widget::Input('fields[type]', $fields['type']));

			if(isset($this->_errors['type'])) {
				$label = Widget::Error($label, $this->_errors['type']);
			}

			$column->appendChild($label);

			$tags = new XMLElement('ul');
			$tags->setAttribute('class', 'tags');

			$types = PageManager::fetchAvailablePageTypes();
			foreach($types as $type) {
				$tags->appendChild(new XMLElement('li', $type));
			}

			$column->appendChild($tags);
			$group->appendChild($column);
			$fieldset->appendChild($group);
			$this->Form->appendChild($fieldset);

		// Events -------------------------------------------------------------

			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Page Resources')));

			$group = new XMLElement('div');
			$group->setAttribute('class', 'two columns');

			$label = Widget::Label(__('Events'));
			$label->setAttribute('class', 'column');

			$events = ResourceManager::fetch(RESOURCE_TYPE_EVENT, array(), array(), 'name ASC');
			$options = array();

			if(is_array($events) && !empty($events)) {
				if(!is_array($fields['events'])) $fields['events'] = array();
				foreach ($events as $name => $about) $options[] = array(
					$name, in_array($name, $fields['events']), $about['name']
				);
			}

			$label->appendChild(Widget::Select('fields[events][]', $options, array('multiple' => 'multiple')));
			$group->appendChild($label);

		// Data Sources -------------------------------------------------------

			$label = Widget::Label(__('Data Sources'));
			$label->setAttribute('class', 'column');

			$datasources = ResourceManager::fetch(RESOURCE_TYPE_DS, array(), array(), 'name ASC');
			$options = array();

			if(is_array($datasources) && !empty($datasources)) {
				if(!is_array($fields['data_sources'])) $fields['data_sources'] = array();
				foreach ($datasources as $name => $about) $options[] = array(
					$name, in_array($name, $fields['data_sources']), $about['name']
				);
			}

			$label->appendChild(Widget::Select('fields[data_sources][]', $options, array('multiple' => 'multiple')));
			$group->appendChild($label);
			$fieldset->appendChild($group);
			$this->Form->appendChild($fieldset);

		// Controls -----------------------------------------------------------

			/**
			 * After all Page related Fields have been added to the DOM, just before the
			 * actions.
			 *
			 * @delegate AppendPageContent
			 * @param string $context
			 *  '/blueprints/pages/'
			 * @param XMLElement $form
			 * @param array $fields
			 * @param array $errors
			 */
			Symphony::ExtensionManager()->notifyMembers(
				'AppendPageContent',
				'/blueprints/pages/',
				array(
					'form'		=> &$this->Form,
					'fields'	=> &$fields,
					'errors'	=> $this->_errors
				)
			);

			$div = new XMLElement('div');
			$div->setAttribute('class', 'actions');
			$div->appendChild(Widget::Input(
				'action[save]', ($this->_context[0] == 'edit' ? __('Save Changes') : __('Create Page')),
				'submit', array('accesskey' => 's')
			));

			if($this->_context[0] == 'edit'){
				$button = new XMLElement('button', __('Delete'));
				$button->setAttributeArray(array('name' => 'action[delete]', 'class' => 'button confirm delete', 'title' => __('Delete this page'), 'accesskey' => 'd', 'data-message' => __('Are you sure you want to delete this page?')));
				$div->appendChild($button);

				// Add the unique hash field:
				$hashField = Widget::Input('fields[unique_hash]', $fields['unique_hash'], 'hidden');
				$div->appendChild($hashField);
			}

			$this->Form->appendChild($div);

			if(isset($_REQUEST['parent']) && is_numeric($_REQUEST['parent'])){
				$this->Form->appendChild(new XMLElement('input', NULL, array('type' => 'hidden', 'name' => 'parent', 'value' => $_REQUEST['parent'])));
			}
		}

		public function __actionIndex() {
			$checked = (is_array($_POST['items'])) ? array_keys($_POST['items']) : null;

			if(is_array($checked) && !empty($checked)) {
				switch ($_POST['with-selected']) {
					case 'delete':
						$this->__actionDelete($checked, SYMPHONY_URL . '/blueprints/pages/');
						break;
				}
			}
		}

		public function __actionTemplate() {
			$filename = $this->_context[1] . '.xsl';
			$file_abs = PAGES . '/' . $filename;
			$fields = $_POST['fields'];
			$this->_errors = array();

			if(!isset($fields['body']) || trim($fields['body']) == '') {
				$this->_errors['body'] = __('This is a required field.');
			}
			else if(!General::validateXML($fields['body'], $errors, false, new XSLTProcess())) {
				$this->_errors['body'] = __('This document is not well formed.') . ' ' . __('The following error was returned:') . ' <code>' . $errors[0]['message'] . '</code>';
			}

			if(empty($this->_errors)) {
				/**
				 * Just before a Page Template is about to written to disk
				 *
				 * @delegate PageTemplatePreEdit
				 * @since Symphony 2.2.2
				 * @param string $context
				 * '/blueprints/pages/template/'
				 * @param string $file
				 *  The path to the Page Template file
				 * @param string $contents
				 *  The contents of the `$fields['body']`, passed by reference
				 */
				Symphony::ExtensionManager()->notifyMembers('PageTemplatePreEdit', '/blueprints/pages/template/', array('file' => $file_abs, 'contents' => &$fields['body']));

				if(!PageManager::writePageFiles($file_abs, $fields['body'])) {
					$this->pageAlert(
						__('Page Template could not be written to disk.')
						. ' ' . __('Please check permissions on %s.', array('<code>/workspace/pages</code>'))
						, Alert::ERROR
					);

				}
				else {
					/**
					 * Just after a Page Template has been edited and written to disk
					 *
					 * @delegate PageTemplatePostEdit
					 * @since Symphony 2.2.2
					 * @param string $context
					 * '/blueprints/pages/template/'
					 * @param string $file
					 *  The path to the Page Template file
					 */
					Symphony::ExtensionManager()->notifyMembers('PageTemplatePostEdit', '/blueprints/pages/template/', array('file' => $file_abs));

					redirect(SYMPHONY_URL . '/blueprints/pages/template/' . $this->_context[1] . '/saved/');
				}
			}
		}

		public function __actionNew() {
			$this->__actionEdit();
		}

		public function __actionEdit() {
			if($this->_context[0] != 'new' && !$page_id = (integer)$this->_context[1]) {
				redirect(SYMPHONY_URL . '/blueprints/pages/');
			}

			$parent_link_suffix = NULL;
			if(isset($_REQUEST['parent']) && is_numeric($_REQUEST['parent'])){
				$parent_link_suffix = '?parent=' . $_REQUEST['parent'];
			}

			if(@array_key_exists('delete', $_POST['action'])) {
				$this->__actionDelete($page_id, SYMPHONY_URL  . '/blueprints/pages/' . $parent_link_suffix);
			}

			if(@array_key_exists('save', $_POST['action'])) {

				$fields = $_POST['fields'];
				$this->_errors = array();
				$autogenerated_handle = false;

				if(!isset($fields['title']) || trim($fields['title']) == '') {
					$this->_errors['title'] = __('This is a required field');
				}

				if(trim($fields['type']) != '' && preg_match('/(index|404|403)/i', $fields['type'])) {
					$types = preg_split('/\s*,\s*/', strtolower($fields['type']), -1, PREG_SPLIT_NO_EMPTY);

					if(in_array('index', $types) && PageManager::hasPageTypeBeenUsed($page_id, 'index')) {
						$this->_errors['type'] = __('An index type page already exists.');
					}

					elseif(in_array('404', $types) && PageManager::hasPageTypeBeenUsed($page_id, '404')) {
						$this->_errors['type'] = __('A 404 type page already exists.');
					}

					elseif(in_array('403', $types) && PageManager::hasPageTypeBeenUsed($page_id, '403')) {
						$this->_errors['type'] = __('A 403 type page already exists.');
					}
				}

				if(trim($fields['handle'] ) == '') {
					$fields['handle'] = $fields['title'];
					$autogenerated_handle = true;
				}

				$fields['handle'] = PageManager::createHandle($fields['handle']);
				if(empty($fields['handle']) && !isset($this->_errors['title'])) {
					$this->_errors['handle'] = __('Please ensure handle contains at least one Latin-based character.');
				}

				/**
				 * Just after the Symphony validation has run, allows Developers
				 * to run custom validation logic on a Page
				 *
				 * @delegate PagePostValidate
				 * @since Symphony 2.2
				 * @param string $context
				 * '/blueprints/pages/'
				 * @param array $fields
				 *  The `$_POST['fields']` array. This should be read-only and not changed
				 *  through this delegate.
				 * @param array $errors
				 *  An associative array of errors, with the key matching a key in the
				 *  `$fields` array, and the value being the string of the error. `$errors`
				 *  is passed by reference.
				 */
				Symphony::ExtensionManager()->notifyMembers('PagePostValidate', '/blueprints/pages/', array('fields' => $fields, 'errors' => &$errors));

				if(empty($this->_errors)) {

					$autogenerated_handle = false;

					if($fields['params']) {
						$fields['params'] = trim(preg_replace('@\/{2,}@', '/', $fields['params']), '/');
					}

					// Clean up type list
					$types = preg_split('/\s*,\s*/', $fields['type'], -1, PREG_SPLIT_NO_EMPTY);
					$types = @array_map('trim', $types);

                    /**
                     * Just before the page's types are saved into the pages' XML file.
                     * Use with caution as no further processing is done on the `$types`
                     * array to prevent duplicate `$types` from occurring (ie. two index
                     * page types). Your logic can use the PageManger::hasPageTypeBeenUsed
                     * function to perform this logic.
                     *
                     * @delegate PageTypePreCreate
                     * @since Symphony 2.2
                     * @see toolkit.PageManager#hasPageTypeBeenUsed
                     * @param string $context
                     * '/blueprints/pages/'
                     * @param integer $page_id
                     *  The ID of the Page that was just created or updated
                     * @param array $types
                     *  An associative array of the types for this page passed by reference.
                     */
                    Symphony::ExtensionManager()->notifyMembers('PageTypePreCreate', '/blueprints/pages/', array('page_id' => $page_id, 'types' => &$types));

                    $fields['type'] = $types;
					// unset($fields['type']);

					$fields['parent'] = ($fields['parent'] != __('None') ? $fields['parent'] : null);
					$fields['data_sources'] = is_array($fields['data_sources']) ? implode(',', $fields['data_sources']) : NULL;
					$fields['events'] = is_array($fields['events']) ? implode(',', $fields['events']) : NULL;
					$fields['path'] = null;

					if($fields['parent']) {
						$fields['path'] = PageManager::resolvePagePath((integer)$fields['parent']);
					}

					// Check for duplicates:
					$current = PageManager::fetchPageByID($page_id);

					if(empty($current)) {
						$fields['sortorder'] = PageManager::fetchNextSortOrder();
					}

					$where = array();

					$xpath = 'page';

					if(!empty($current)) {
						// $where[] = "p.id != {$page_id}";
						$where[] = 'unique_hash!=\''.PageManager::lookup()->getHash($page_id).'\'';
					}
					// $where[] = "p.handle = '" . $fields['handle'] . "'";
					$where[] = 'title/@handle=\''.$fields['handle'].'\'';
					$where[] = (is_null($fields['path']))
						? 'path=\'\''
						: 'path=\''.$fields['path'].'\'';
					$xpath .= '['.implode(' and ', $where).']';
					// $duplicate = PageManager::fetch(false, array('*'), $where);
					$duplicate = PageManager::fetchByXPath($xpath);

					// If duplicate
					if(!empty($duplicate)) {
						if($autogenerated_handle) {
							$this->_errors['title'] = __('A page with that title already exists');
						}
						else {
							$this->_errors['handle'] = __('A page with that handle already exists');
						}
					}
					// Create or move files:
					else {
						// New page?
						if(empty($current)) {
							$file_created = PageManager::createPageFiles(
								$fields['path'], $fields['handle']
							);
						}
						// Existing page, potentially rename files
						else {
							$file_created = PageManager::createPageFiles(
								$fields['path'], $fields['handle'],
								$current['path'], $current['handle']
							);
						}

						// If the file wasn't created, it's usually permissions related
						if(!$file_created) {
							$redirect = null;
							return $this->pageAlert(
								__('Page Template could not be written to disk.')
								. ' ' . __('Please check permissions on %s.', array('<code>/workspace/pages</code>'))
								, Alert::ERROR
							);
						}

						// Insert the new data:
						if(empty($current)) {

							/**
							 * Just prior to creating a new Page XML file in `workspace/pages`, provided
							 * with the `$fields` associative array. Use with caution, as no
							 * duplicate page checks are run after this delegate has fired
							 *
							 * @delegate PagePreCreate
							 * @since Symphony 2.2
							 * @param string $context
							 * '/blueprints/pages/'
							 * @param array $fields
							 *  The `$_POST['fields']` array passed by reference
							 */
							Symphony::ExtensionManager()->notifyMembers('PagePreCreate', '/blueprints/pages/', array('fields' => &$fields));

							if(!$page_id = PageManager::add($fields)) {
								$this->pageAlert(
									__('Unknown errors occurred while attempting to save.')
									. '<a href="' . SYMPHONY_URL . '/system/log/">'
									. __('Check your activity log')
									. '</a>.'
									, Alert::ERROR);

							}
							else {
								/**
								 * Just after the creation of a new page XML in `workspace/pages`
								 *
								 * @delegate PagePostCreate
								 * @since Symphony 2.2
								 * @param string $context
								 * '/blueprints/pages/'
								 * @param integer $page_id
								 *  The ID of the newly created Page
								 * @param array $fields
								 *  An associative array of data that was just saved for this page
								 */
								Symphony::ExtensionManager()->notifyMembers('PagePostCreate', '/blueprints/pages/', array('page_id' => $page_id, 'fields' => &$fields));

								$redirect = "/blueprints/pages/edit/{$page_id}/created/{$parent_link_suffix}";
							}

						}
						// Update existing:
						else {

							/**
							 * Just prior to updating a Page XML in `workspace/pages`, provided
							 * with the `$fields` associative array. Use with caution, as no
							 * duplicate page checks are run after this delegate has fired
							 *
							 * @delegate PagePreEdit
							 * @since Symphony 2.2
							 * @param string $context
							 * '/blueprints/pages/'
							 * @param integer $page_id
							 *  The ID of the Page that is about to be updated
							 * @param array $fields
							 *  The `$_POST['fields']` array passed by reference
							 */
							Symphony::ExtensionManager()->notifyMembers('PagePreEdit', '/blueprints/pages/', array('page_id' => $page_id, 'fields' => &$fields));

							if(!PageManager::edit($page_id, $fields, true)) {
								return $this->pageAlert(
										__('Unknown errors occurred while attempting to save.')
										. '<a href="' . SYMPHONY_URL . '/system/log/">'
										. __('Check your activity log')
										. '</a>.'
										, Alert::ERROR);

							}
							else {
								/**
								 * Just after updating a pages' XML in `workspace/pages`
								 *
								 * @delegate PagePostEdit
								 * @since Symphony 2.2
								 * @param string $context
								 * '/blueprints/pages/'
								 * @param integer $page_id
								 *  The ID of the Page that was just updated
								 * @param array $fields
								 *  An associative array of data that was just saved for this page
								 */
								Symphony::ExtensionManager()->notifyMembers('PagePostEdit', '/blueprints/pages/', array('page_id' => $page_id, 'fields' => $fields));

								$redirect = "/blueprints/pages/edit/{$page_id}/saved/{$parent_link_suffix}";
							}
						}
					}

					// Only proceed if there was no errors saving/creating the page
					if(empty($this->_errors)) {

						// Find and update children:
						if($this->_context[0] == 'edit') {
							PageManager::editPageChildren($page_id, $fields['path'] . '/' . $fields['handle']);
						}

						if($redirect) redirect(SYMPHONY_URL . $redirect);
					}
				}

				// If there was any errors, either with pre processing or because of a
				// duplicate page, return.
				if(is_array($this->_errors) && !empty($this->_errors)) {
					return $this->pageAlert(
						__('An error occurred while processing this form. See below for details.')
						, Alert::ERROR
					);
				}
			}
		}

		public function __actionDelete($pages, $redirect) {
			$success = true;
			$deleted_page_ids = array();

			if(!is_array($pages)) $pages = array($pages);

			/**
			 * Prior to deleting Pages
			 *
			 * @delegate PagePreDelete
			 * @since Symphony 2.2
			 * @param string $context
			 * '/blueprints/pages/'
			 * @param array $page_ids
			 *  An array of Page ID's that are about to be deleted, passed
			 *  by reference
			 * @param string $redirect
			 *  The absolute path that the Developer will be redirected to
			 *  after the Pages are deleted
			 */
			Symphony::ExtensionManager()->notifyMembers('PagePreDelete', '/blueprints/pages/', array('page_ids' => &$pages, 'redirect' => &$redirect));

			foreach($pages as $page_id) {
				$page = PageManager::fetchPageByID($page_id);

				if(empty($page)) {
					$success = false;
					$this->pageAlert(
						__('Page could not be deleted because it does not exist.'),
						Alert::ERROR
					);

					break;
				}

				if(PageManager::hasChildPages($page_id)) {
					$this->_hilights[] = $page['id'];
					$success = false;
					$this->pageAlert(
						__('Page could not be deleted because it has children.'),
						Alert::ERROR
					);

					continue;
				}

				if(!PageManager::deletePageFiles($page['path'], $page['handle'])) {
					$this->_hilights[] = $page['id'];
					$success = false;
					$this->pageAlert(
						__('One or more pages could not be deleted.')
						. ' ' . __('Please check permissions on %s.', array('<code>/workspace/pages</code>'))
						, Alert::ERROR
					);

					continue;
				}

				if(PageManager::delete($page_id, false)) {
					$deleted_page_ids[] = $page_id;
				}
			}

			if($success) {
				/**
				 * Fires after all Pages have been deleted
				 *
				 * @delegate PagePostDelete
				 * @since Symphony 2.3
				 * @param string $context
				 * '/blueprints/pages/'
				 * @param array $page_ids
				 *  The page ID's that were just deleted
				 */
				Symphony::ExtensionManager()->notifyMembers('PagePostDelete', '/blueprints/pages/', array('page_ids' => $deleted_page_ids));
				redirect($redirect);
			}
		}

		/**
		 * Returns boolean if a the given `$type` is set for
		 * the given `$page_id`.
		 *
		 * @deprecated This will be removed in Symphony 2.4.
		 *  The preferred function is `PageManger::hasPageTypeBeenUsed`
		 * @see toolkit.PageManager#hasPageTypeBeenUsed
		 * @param integer $page_id
		 *  The ID of the Page to check
		 * @param string $type
		 * @return boolean
		 *  True if the type is used, false otherwise
		 */
		public static function typeUsed($page_id, $type) {
			return PageManager::hasPageTypeBeenUsed($page_id, $type);
		}

		public function __viewDiff(){
			// Check if accept or reject is clicked:
			$context = $this->getContext();
			if(isset($context[1]))
			{
				switch($context[1])
				{
					case 'accept' :
						{
							$this->__acceptDiff();
							/* Todo: Show the notice after the redirect: */
							Administration::instance()->Page->pageAlert(__('Page modifications are successfully accepted.'), Alert::SUCCESS);
							redirect(SYMPHONY_URL.'/blueprints/pages/');
							break;
						}
					case 'reject' :
						{
							$this->__rejectDiff();
							/* Todo: Show the notice after the redirect: */
							Administration::instance()->Page->pageAlert(__('Page modifications are successfully rejected.'), Alert::SUCCESS);
							redirect(SYMPHONY_URL.'/blueprints/pages/');
							break;
						}
					default:
						{
							// Invalid URL, redirect to diff screen:
							redirect(SYMPHONY_URL.'/blueprints/pages/diff/');
							break;
						}
				}
			}

			$this->setTitle(__('%1$s &ndash; %2$s', array(__('Pages Differences'), __('Symphony'))));
			$this->addStylesheetToHead(SYMPHONY_URL.'/assets/css/symphony.diff.css');

			// Create the head:
			$tableHead = Widget::TableHead(array(
				array(__('Page')),
				array(__('Changes')),
			));

			// Get the indexes:
			$cachedIndex = PageManager::index()->getIndex();
			$localIndex  = PageManager::index()->getLocalIndex();

			// Check if the local index is valid:
			if($localIndex === false)
			{
				$error = true;
				$tableRows[] = Widget::TableRow(array(
					new XMLElement('td', __('XML Error')),
					new XMLElement('td', __('One ore more XML files are not well-formed. Please validate your XML first.')),
				), 'error');
			} else {
				// Well, at least our XML is valid! ;-)

				// This is an array to keep track of the rows added to our table:
				$tableRows = array();

				// Array to keep track of the sections that are already found:
				$foundPages = array();

				// Flag if an error is found (and changes cannot be accepted):
				$error = false;

				// Check the cached sections:
				foreach($cachedIndex->xpath('page') as $cachedPage)
				{
					$rowClass = null;
					$localPages = $localIndex->xpath(
						sprintf('page[unique_hash=\'%s\']', (string)$cachedPage->unique_hash)
					);
					if(count($localPages) == 1)
					{
						$localPage = $localPages[0];
						// Page found in local index, check for differences:
						$cachedRow = new XMLElement('td', (string)$cachedPage->title);
						// Check if the parsed XML is identical:
						if($cachedPage->saveXML() == $localPage->saveXML())
						{
							$localRow = new XMLElement('td', __('No changes found.'));
							$rowClass = 'no-changes';
						} else {
							// Validate the page:
							$result = $this->__validatePage($localPage, $localIndex);
							if($result !== true)
							{
								// Page does not validate:
								$localRow = new XMLElement('td', $result);
								$error = true;
								$rowClass = 'error';
							} else {
								// page validates:
								$localRow = new XMLElement('td', __('Page is modified:'));
								// Show changes:
								$changes = new XMLElement('ul');
								foreach($cachedPage->children() as $cachedElement)
								{
									// Iterate through each element to detect changes:
									$name = $cachedElement->getName();
									switch($name)
									{
										case 'types' :
											{
												$cachedTypes = array();
												$localTypes = array();
												foreach($cachedElement->children() as $cachedType)
												{
													$cachedTypes[] = (string)$cachedType;
												}
												// Check if there are new types in the localElement:
												$localElements = $localPage->xpath('types/type');
												foreach($localElements as $localType)
												{
													$type = (string)$localType;

													if(!in_array($type, $cachedTypes))
													{
														// Type not found. This is a new type and will be added
														$changes->appendChild(
															new XMLElement('li', sprintf(__('Type <em>\'%s\'</em> is new and will be added.'), $type))
														);
													}
													$localTypes[] = $type;
												}
												// Check if there are types to be removed:
												foreach($cachedTypes as $type)
												{
													if(!in_array($type, $localTypes))
													{
														// Type not found. This is a new type and will be added
														$changes->appendChild(
															new XMLElement('li', sprintf(__('Type <em>\'%s\'</em> will be removed.'), $type))
														);
													}
												}
												break;
											}
										case 'datasources' :
											{
												// Check if there are removed datasources:
												$cachedDatasources = array();
												foreach($cachedPage->xpath('datasources/datasource') as $cachedDatasource)
												{
													if(count($localPage->xpath(sprintf('datasources[datasource=\'%s\']', (string)$cachedDatasource))) == 0)
													{
														$changes->appendChild(
															new XMLElement('li', sprintf(__('Datasource <em>\'%s\'</em> is going to be removed from this page.'),
															(string)$cachedDatasource))
														);
													}
													$cachedDatasources[] = (string)$cachedDatasource;
												}
												// Check if datasources are added to the page:
												foreach($localPage->xpath('datasources/datasource') as $localDatasource)
												{
													if(!in_array((string)$localDatasource, $cachedDatasources)) {
														$changes->appendChild(
															new XMLElement('li', sprintf(__('Datasource <em>\'%s\'</em> is going to be added to this page.'),
															(string)$localDatasource))
														);
													}
												}
												break;
											}
										case 'events' :
											{
												// Check if there are removed events:
												$cachedEvents = array();
												foreach($cachedPage->xpath('events/event') as $cachedEvent)
												{
													if(count($localPage->xpath(sprintf('events[event=\'%s\']', (string)$cachedEvent))) == 0)
													{
														$changes->appendChild(
															new XMLElement('li', sprintf(__('Event <em>\'%s\'</em> is going to be removed from this page.'),
															(string)$cachedEvent))
														);
													}
													$cachedEvents[] = (string)$cachedEvent;
												}
												// Check if events are added to the page:
												foreach($localPage->xpath('events/event') as $localEvent)
												{
													if(!in_array((string)$localEvent, $cachedEvents)) {
														$changes->appendChild(
															new XMLElement('li', sprintf(__('Event <em>\'%s\'</em> is going to be added to this page.'),
															(string)$localEvent))
														);
													}
												}
												break;
											}
										default:
											{
												// See if this element exists in the local section:
												$localElements = $localPage->xpath($name);
												if(count($localElements) == 1)
												{
													// Local element found, check if there are differences:
													$localElement = $localElements[0];
													if($cachedElement->saveXML() != $localElement->saveXML())
													{
														// Not identical:
														$changes->appendChild(
															new XMLElement('li', sprintf(__('Element <em>\'%s\'</em> : %s → %s'), $name,
																(string)$cachedElement,
																(string)$localElement
															))
														);
													}
												} else {
													// Local element not found: element will be added to the XML:
													$changes->appendChild(
														new XMLElement('li', sprintf(__('Element <em>\'%s\'</em> not found. Element will be added.'), $name))
													);
												}
												break;
											}
									}
								}
								$localRow->appendChild($changes);
							}
						}
					} elseif(count($localPages) > 1) {
						// Page with duplicate hashes found. This is not allowed:
						$cachedRow = new XMLElement('td', (string)$cachedPage->title);
						$localRow  = new XMLElement('td', __('Duplicate hash found for this page.'));
						$error = true;
						$rowClass = 'error';
					} else {
						// Page not found in local index, section is going to be deleted:
						$cachedRow = new XMLElement('td', (string)$cachedPage->title);
						$localRow  = new XMLElement('td', __('The page is not found in the local index. This page is going to be deleted'));
						$rowClass = 'alert';
					}
					$tableRows[] = Widget::TableRow(array($cachedRow, $localRow), $rowClass);
					// Add the unique hash to the foundpages:
					$foundPages[] = (string)$cachedPage->unique_hash;
				}

				// Check the local pages (to see if there are pages added):
				foreach($localIndex->xpath('page') as $localPage)
				{
					$rowClass = null;
					if(!in_array((string)$localPage->unique_hash, $foundPages))
					{
						// This page is new, check if it validates:
						$result = $this->__validatePage($localPage, $localIndex);
						$cachedRow = new XMLElement('td', (string)$localPage->title);
						if($result !== true)
						{
							// Page does not validate:
							$localRow = new XMLElement('td', $result);
							$error = true;
							$rowClass = 'error';
						} else {
							// Page validates, do additional checks:
							// Check if the hash of the page is unique:
							if(count($cachedIndex->xpath(sprintf('page[unique_hash=\'%s\']', (string)$localPage->unique_hash))) > 0)
							{
								$error = true;
								$localRow = new XMLElement('td', __('Duplicate hash found for this page.'));
								$rowClass = 'error';
							} else {
								$localRow = new XMLElement('td', __('This page is new and will be created.'));
								// $rowClass = 'notice';
							}
						}
						$tableRows[] = Widget::TableRow(array($cachedRow, $localRow), $rowClass);
					}
				}
			}

			$tableBody = Widget::TableBody($tableRows);

			$table = Widget::Table($tableHead, null, $tableBody, 'diff');

			if(!$error)
			{
				$list = new XMLElement('ul', null, array('class'=>'actions'));
				$list->appendChild(new XMLElement('li', Widget::Anchor(__('Accept Changes'), SYMPHONY_URL.'/blueprints/pages/diff/accept/', __('Accept Changes'), 'create button', NULL, array('accesskey' => 'a'))));
				$list->appendChild(new XMLElement('li', Widget::Anchor(__('Reject Changes'), SYMPHONY_URL.'/blueprints/pages/diff/reject/', __('Reject Changes'), 'button', NULL, array('accesskey' => 'r'))));
				$this->appendSubheading(__('Page Differences'));
				$this->Context->appendChild($list);
			} else {
				$this->Contents->appendChild(new XMLElement('p', __('The changes cannot be accepted for one ore more reasons. Please see the report below to find out what\'s wrong:'), array('class'=>'diff-notice')));
				$this->appendSubheading(__('Page Differences'), Widget::Anchor(__('Reject Changes'), SYMPHONY_URL.'/blueprints/pages/diff/reject/', __('Reject Changes'), 'button', NULL, array('accesskey' => 'r')));
			}
			$this->Contents->appendChild($table);

		}

		/**
		 * Validate page XML
		 *
		 * @param $pageElement
		 *  The page XML element
		 * @param $index
		 *  The index to check against
		 * @return bool|string
		 *  true on success, the error message on failure
		 */
		private function __validatePage($pageElement, $index)
		{
			// First check if all the required elements are there:
			$requiredElements = array('title', 'sortorder', 'unique_hash');
			foreach($requiredElements as $elementName)
			{
				if((string)$pageElement->$elementName == '')
				{
					return sprintf(__('Required element \'%s\' is not found. Changes cannot be accepted.'), $elementName);
				}
			}
			// Check sortorder:
			if(!is_numeric((string)$pageElement->sortorder))
			{
				return __('<em>\'sortorder\'</em> must be numeric.');
			}
			// Check for duplicate handle:
			$localPath = (string)$pageElement->path;
			$localHandle = (string)$pageElement->title['handle'];
			if(count($index->xpath(sprintf('page[path=\'%s\' and title/@handle=\'%s\']', $localPath, $localHandle))) > 1)
			{
				return __('Duplicate handle found for this level.');
			}
			// Check if the pages XML file and path matches it's handle:
			$filename = !empty($localPath) ? str_replace('/', '_', $localPath).'_'.$localHandle : $localHandle;
			if(!file_exists(WORKSPACE.'/pages/'.$filename.'.xml'))
			{
				return sprintf(__('Invalid filename. The filename must be <em>\'%s.xml\'</em>.'), $filename);
			} else {
				// Extra check to make sure that this is the correct XML-file (since we are working with the
				// index, we don't know the filename by hand:
				$xml = simplexml_load_file(WORKSPACE.'/pages/'.$filename.'.xml');
				if((string)$xml->unique_hash != (string)$pageElement->unique_hash)
				{
					return sprintf(__('Invalid filename. The filename must be <em>\'%s.xml\'</em>, or the handle should be adjusted to correspond with the filename.'), $filename);
				}
			}
			// Check if there is a matching XSL-file:
			if(!file_exists(WORKSPACE.'/pages/'.$filename.'.xsl'))
			{
				return sprintf(__('Template not found: <em>\'%s.xsl\'</em>.'), $filename);
			}
			// Check if parent exists:
			$localParent = (string)$pageElement->parent;
			if(!empty($localParent) && count($index->xpath(sprintf('page[unique_hash=\'%s\']', $localParent))) == 0)
			{
				return __('The parent page is not found.');
			}
			// Validate types:
			// Check that there is only 1 index-type:
			if(count($index->xpath('page[types/type=\'index\']')) > 1)
			{
				return __('Only one page can be of type <em>\'index\'</em>.');
			}
			// Validate datasources:
			$datasources = array_keys(DatasourceManager::listAll());
			foreach($pageElement->xpath('datasources/datasource') as $datasourceElement)
			{
				if(!in_array((string)$datasourceElement, $datasources))
				{
					// The datasource is not found:
					return sprintf(__('Datasource <em>\'%s\'</em> not found. Changes cannot be accepted.'), (string)$datasourceElement);
				}
			}
			// Validate events:
			$events = array_keys(EventManager::listAll());
			foreach($pageElement->xpath('events/event') as $eventElement)
			{
				if(!in_array((string)$eventElement, $events))
				{
					// The datasource is not found:
					return sprintf(__('Event <em>\'%s\'</em> not found. Changes cannot be accepted.'), (string)$eventElement);
				}
			}
			// Everything seems ok from here...
			return true;
		}

		/**
		 * Function to accept the diff. Use the local XML files to edit the pages
		 */
		private function __acceptDiff()
		{
			// Get the indexes:
			$cachedIndex = PageManager::index()->getIndex();
			$localIndex  = PageManager::index()->getLocalIndex();

			// Array to keep track of the sections that are already found:
			$foundPages = array();

			// Check the cached sections:
			foreach($cachedIndex->xpath('page') as $cachedPage)
			{
				// Check the differences:
				$localPages = $localIndex->xpath(
					sprintf('page[unique_hash=\'%s\']', (string)$cachedPage->unique_hash)
				);
				if(count($localPages) == 1)
				{
					// This page is found, edit it according to it's local page:
					$localPage = $localPages[0];
					$data = array();
					$this->__buildData($data, 'handle', (string)$localPage->title['handle']);
					$this->__buildData($data, 'title', (string)$localPage->title);
					$this->__buildData($data, 'path', (string)$localPage->path);
					$this->__buildData($data, 'params', (string)$localPage->params);
					$this->__buildData($data, 'sortorder', (string)$localPage->sortorder);
					$this->__buildData($data, 'parent', (string)$localPage->parent);

					// Datasources:
					$data['data_sources'] = array();
					foreach($localPage->xpath('datasources/datasource') as $localDatasource)
					{
						$data['data_sources'][] = (string)$localDatasource;
					}

					// Events:
					$data['events'] = array();
					foreach($localPage->xpath('events/event') as $localEvent)
					{
						$data['events'][] = (string)$localEvent;
					}

					// Types:
					$data['type'] = array();
					foreach($localPage->xpath('types/type') as $localType)
					{
						$data['type'][] = (string)$localType;
					}

					PageManager::edit(
						PageManager::lookup()->getId((string)$cachedPage->unique_hash), $data
					);
				} else {
					// Page not found in local index, page is going to be deleted:
					PageManager::delete(
						PageManager::lookup()->getId((string)$cachedPage->unique_hash)
					);
					// Manually delete the ID in the lookup table (since the delete function in the PageManager
					// will return false if the XML and XSL files are not found to be deleted for):
					PageManager::lookup()->delete((string)$cachedPage->unique_hash);
				}
				$foundPages[] = (string)$cachedPage->unique_hash;
			}

			// Check the local pages (to see if there are pages added):
			foreach($localIndex->xpath('page') as $localPage)
			{
				if(!in_array((string)$localPage->unique_hash, $foundPages))
				{
					// This is a new page
					$data = array(
						'title'				=> (string)$localPage->title,
						'handle' 			=> (string)$localPage->handle['handle'],
						'path'				=> (string)$localPage->path,
						'params'			=> (string)$localPage->params,
						'sortorder' 		=> (string)$localPage->sortorder,
						'unique_hash'		=> (string)$localPage->unique_hash
					);

					// Datasources:
					$data['data_sources'] = array();
					foreach($localPage->xpath('datasources/datasource') as $localDatasource)
					{
						$data['data_sources'][] = (string)$localDatasource;
					}

					// Events:
					$data['events'] = array();
					foreach($localPage->xpath('events/event') as $localEvent)
					{
						$data['events'][] = (string)$localEvent;
					}

					// Types:
					$data['type'] = array();
					foreach($localPage->xpath('types/type') as $localType)
					{
						$data['type'][] = (string)$localType;
					}

					// This is a new page, add it:
					PageManager::add($data);
				}
			}
		}

		/**
		 * Little helper function to help build the $data-array in __acceptDiff()
		 *
		 * @param $data
		 *  A reference to the data
		 * @param $key
		 *  Array key
		 * @param $value
		 *  The value
		 * @return void
		 */
		private function __buildData(&$data, $key, $value)
		{
			if(!empty($value))
			{
				$data[$key] = $value;
			}
		}

		/**
		 * Reject the diff. Use the cached XML tree to re-generate the page XML files.
		 */
		private function __rejectDiff()
		{
			// Delete all local page XML files:
			// Todo: XSL files are now manually to be deleted. Can this be automated?
			$files = glob(WORKSPACE.'/pages/*.xml');
			foreach($files as $file)
			{
				General::deleteFile($file);
			}

			// Store the cached pages as new XML files:
			$index = PageManager::index()->getIndex();
			foreach($index->children() as $cachedPage)
			{
				$xml = PageManager::index()->getFormattedXML(
					sprintf('page[unique_hash=\'%s\']', (string)$cachedPage->unique_hash)
				);

				$cachedPath = (string)$cachedPage->path;
				$cachedHandle = (string)$cachedPage->title['handle'];
				$filename = empty($cachedPath) ? str_replace('/', '_', $cachedPath).'_'.$cachedHandle : $cachedHandle;

				PageManager::writePageFiles(WORKSPACE.'/pages/'.$filename.'.xml', $xml);
			}

			// Clear the cache and reIndex:
			PageManager::index()->reIndex();
		}

	}
