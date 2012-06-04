<?php
	/**
	 * @package content
	 */

	/**
	 * This page controls the creation and maintenance of Symphony
	 * Sections through the Section Index and Section Editor.
	 */
	require_once(TOOLKIT . '/class.administrationpage.php');
	require_once(TOOLKIT . '/class.sectionmanager.php');
	require_once(TOOLKIT . '/class.fieldmanager.php');
	require_once(TOOLKIT . '/class.entrymanager.php');

	Class contentBlueprintsSections extends AdministrationPage{

		public $_errors = array();

		public function __viewIndex(){
			$this->setPageType('table');
			$this->setTitle(__('%1$s &ndash; %2$s', array(__('Sections'), __('Symphony'))));
			$this->appendSubheading(__('Sections'), Widget::Anchor(__('Create New'), Administration::instance()->getCurrentPageURL().'new/', __('Create a section'), 'create button', NULL, array('accesskey' => 'c')));

			// $sections = SectionManager::fetch(NULL, 'ASC', 'sortorder');
			$sections = SectionManager::fetchByXPath();

			$aTableHead = array(
				array(__('Name'), 'col'),
				array(__('Entries'), 'col'),
				array(__('Navigation Group'), 'col')
			);

			$aTableBody = array();

			if(!is_array($sections) || empty($sections)){
				$aTableBody = array(
					Widget::TableRow(array(Widget::TableData(__('None found.'), 'inactive', NULL, count($aTableHead))), 'odd')
				);
			}

			else{
				foreach($sections as $s){

					$entry_count = EntryManager::fetchCount($s->get('id'));

					// Setup each cell
					$td1 = Widget::TableData(Widget::Anchor($s->get('name'), Administration::instance()->getCurrentPageURL() . 'edit/' . $s->get('id') .'/', NULL, 'content'));
					$td2 = Widget::TableData(Widget::Anchor("$entry_count", SYMPHONY_URL . '/publish/' . $s->get('handle') . '/'));
					$td3 = Widget::TableData($s->get('navigation_group'));

					$td3->appendChild(Widget::Input('items['.$s->get('id').']', 'on', 'checkbox'));

					// Add a row to the body array, assigning each cell to the row
					$aTableBody[] = Widget::TableRow(array($td1, $td2, $td3));

				}
			}

			$table = Widget::Table(
				Widget::TableHead($aTableHead),
				NULL,
				Widget::TableBody($aTableBody),
				'orderable selectable'
			);

			$this->Form->appendChild($table);

			$tableActions = new XMLElement('div');
			$tableActions->setAttribute('class', 'actions');

			$options = array(
				array(NULL, false, __('With Selected...')),
				array('delete', false, __('Delete'), 'confirm', null, array(
					'data-message' => __('Are you sure you want to delete the selected sections?')
				)),
				array('delete-entries', false, __('Delete Entries'), 'confirm', null, array(
					'data-message' => __('Are you sure you want to delete all entries in the selected sections?')
				))
			);

			if (is_array($sections) && !empty($sections))  {
				$index = 3;
				$options[$index] = array('label' => __('Set navigation group'), 'options' => array());

				$groups = array();
				foreach($sections as $s){
					if(in_array($s->get('navigation_group'), $groups)) continue;
					$groups[] = $s->get('navigation_group');

					$value = 'set-navigation-group-' . urlencode($s->get('navigation_group'));
					$options[$index]['options'][] = array($value, false, $s->get('navigation_group'));
				}
			}

			$tableActions->appendChild(Widget::Apply($options));
			$this->Form->appendChild($tableActions);
		}

		public function __viewNew(){
			$this->setPageType('form');
			$this->setTitle(__('%1$s &ndash; %2$s', array(__('Sections'), __('Symphony'))));
			$this->appendSubheading(__('Untitled'));
			$this->insertBreadcrumbs(array(
				Widget::Anchor(__('Sections'), SYMPHONY_URL . '/blueprints/sections/'),
			));

			$types = array();

			$fields = is_array($_POST['fields']) ? $_POST['fields'] : array();
			$meta = $_POST['meta'];

			$formHasErrors = (is_array($this->_errors) && !empty($this->_errors));

			if($formHasErrors) {
				$this->pageAlert(
					__('An error occurred while processing this form. See below for details.')
					, Alert::ERROR
				);
			}

			$showEmptyTemplate = (is_array($fields) && !empty($fields) ? false : true);

			if(!$showEmptyTemplate) ksort($fields);

			$meta['entry_order'] = (isset($meta['entry_order']) ? $meta['entry_order'] : 'date');
			$meta['hidden'] = (isset($meta['hidden']) ? 'yes' : 'no');

			// Set navigation group, if not already set
			if(!isset($meta['navigation_group'])) {
				$meta['navigation_group'] = (isset($this->_navigation[0]['name']) ? $this->_navigation[0]['name'] : __('Content'));
			}

			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Essentials')));

			$div = new XMLElement('div', NULL, array('class' => 'two columns'));
			$namediv = new XMLElement('div', NULL, array('class' => 'column'));

			$label = Widget::Label(__('Name'));
			$label->appendChild(Widget::Input('meta[name]', General::sanitize($meta['name'])));

			if(isset($this->_errors['name'])) $namediv->appendChild(Widget::Error($label, $this->_errors['name']));
			else $namediv->appendChild($label);

			$label = Widget::Label();
			$input = Widget::Input('meta[hidden]', 'yes', 'checkbox', ($meta['hidden'] == 'yes' ? array('checked' => 'checked') : NULL));
			$label->setValue(__('%s Hide this section from the back-end menu', array($input->generate(false))));
			$namediv->appendChild($label);
			$div->appendChild($namediv);

			$navgroupdiv = new XMLElement('div', NULL, array('class' => 'column'));
			$sections = SectionManager::fetch(NULL, 'ASC', 'sortorder');
			$label = Widget::Label(__('Navigation Group'));
			$label->appendChild(Widget::Input('meta[navigation_group]', $meta['navigation_group']));

			if(isset($this->_errors['navigation_group'])) $navgroupdiv->appendChild(Widget::Error($label, $this->_errors['navigation_group']));
			else $navgroupdiv->appendChild($label);

			if(is_array($sections) && !empty($sections)){
				$ul = new XMLElement('ul', NULL, array('class' => 'tags singular'));
				$groups = array();
				foreach($sections as $s){
					if(in_array($s->get('navigation_group'), $groups)) continue;
					$ul->appendChild(new XMLElement('li', $s->get('navigation_group')));
					$groups[] = $s->get('navigation_group');
				}

				$navgroupdiv->appendChild($ul);
			}

			$div->appendChild($navgroupdiv);

			$fieldset->appendChild($div);

			$this->Form->appendChild($fieldset);

			/**
			 * Allows extensions to add elements to the header of the Section Editor
			 * form. Usually for section settings, this delegate is passed the current
			 * `$meta` array and the `$this->_errors` array.
			 *
			 * @delegate AddSectionElements
			 * @since Symphony 2.2
			 * @param string $context
			 * '/blueprints/sections/'
			 * @param XMLElement $form
			 *  An XMLElement of the current `$this->Form`, just after the Section
			 *  settings have been appended, but before the Fields duplicator
			 * @param array $meta
			 *  The current $_POST['meta'] array
			 * @param array $errors
			 *  The current errors array
			 */
			Symphony::ExtensionManager()->notifyMembers('AddSectionElements', '/blueprints/sections/', array(
				'form' => &$this->Form,
				'meta' => &$meta,
				'errors' => &$this->_errors
			));

			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Fields')));
			$p = new XMLElement('p', __('Click to expand or collapse a field.') . '<br />' . __('Double click to expand or collapse all fields.'), array('class' => 'help'));
			$fieldset->appendChild($p);

			$div = new XMLElement('div', null, array('class' => 'frame'));

			$ol = new XMLElement('ol');
			$ol->setAttribute('id', 'fields-duplicator');
			$ol->setAttribute('data-add', __('Add field'));
			$ol->setAttribute('data-remove', __('Remove field'));

			if(!$showEmptyTemplate){
				foreach($fields as $position => $data){
					if($input = FieldManager::create($data['type'])){
						$input->setArray($data);

						$wrapper = new XMLElement('li');

						$input->set('sortorder', $position);
						$input->displaySettingsPanel($wrapper, (isset($this->_errors[$position]) ? $this->_errors[$position] : NULL));
						$ol->appendChild($wrapper);

					}
				}
			}

			foreach (FieldManager::listAll() as $type) {
				if ($type = FieldManager::create($type)) {
					$types[] = $type;
				}
			}

			uasort($types, create_function('$a, $b', 'return strnatcasecmp($a->_name, $b->_name);'));

			foreach ($types as $type) {
				$defaults = array();

				$type->findDefaults($defaults);
				$type->setArray($defaults);

				$wrapper = new XMLElement('li');
				$wrapper->setAttribute('class', 'template field-' . $type->handle() . ($type->mustBeUnique() ? ' unique' : NULL));
				$wrapper->setAttribute('data-type', $type->handle());

				$type->set('sortorder', '-1');
				$type->displaySettingsPanel($wrapper);

				$ol->appendChild($wrapper);
			}

			$div->appendChild($ol);
			$fieldset->appendChild($div);

			$this->Form->appendChild($fieldset);

			$div = new XMLElement('div');
			$div->setAttribute('class', 'actions');
			$div->appendChild(Widget::Input('action[save]', __('Create Section'), 'submit', array('accesskey' => 's')));

			$this->Form->appendChild($div);

		}

		public function __viewEdit(){

			$section_id = $this->_context[1];

			if(!$section = SectionManager::fetch($section_id)) {
				Administration::instance()->customError(__('Unknown Section'), __('The Section you are looking for could not be found.'));
			}
			$meta = $section->get();
			$section_id = $meta['id'];
			$types = array();

			$formHasErrors = (is_array($this->_errors) && !empty($this->_errors));
			if($formHasErrors) {
				$this->pageAlert(
					__('An error occurred while processing this form. See below for details.')
					, Alert::ERROR
				);
			}
			// These alerts are only valid if the form doesn't have errors
			else if(isset($this->_context[2])) {
				switch($this->_context[2]) {
					case 'saved':
						$this->pageAlert(
							__('Section updated at %s.', array(DateTimeObj::getTimeAgo()))
							. ' <a href="' . SYMPHONY_URL . '/blueprints/sections/new/" accesskey="c">'
							. __('Create another?')
							. '</a> <a href="' . SYMPHONY_URL . '/blueprints/sections/" accesskey="a">'
							. __('View all Sections')
							. '</a>'
							, Alert::SUCCESS);
						break;

					case 'created':
						$this->pageAlert(
							__('Section created at %s.', array(DateTimeObj::getTimeAgo()))
							. ' <a href="' . SYMPHONY_URL . '/blueprints/sections/new/" accesskey="c">'
							. __('Create another?')
							. '</a> <a href="' . SYMPHONY_URL . '/blueprints/sections/" accesskey="a">'
							. __('View all Sections')
							. '</a>'
							, Alert::SUCCESS);
						break;
				}
			}

			if(isset($_POST['fields'])){
				$fields = array();

				if(is_array($_POST['fields']) && !empty($_POST['fields'])){
					foreach($_POST['fields'] as $position => $data){
						if($fields[$position] = FieldManager::create($data['type'])){
							$fields[$position]->setArray($data);
							$fields[$position]->set('sortorder', $position);
						}
					}
				}
			}

			else {
				$fields = FieldManager::fetch(NULL, $section_id);
				$fields = array_values($fields);
			}

			$meta['entry_order'] = (isset($meta['entry_order']) ? $meta['entry_order'] : 'date');

			if(isset($_POST['meta'])){
				$meta = $_POST['meta'];
				$meta['hidden'] = (isset($meta['hidden']) ? 'yes' : 'no');

				if($meta['name'] == '') $meta['name'] = $section->get('name');
			}

			$this->setPageType('form');
			$this->setTitle(__('%1$s &ndash; %2$s &ndash; %3$s', array($meta['name'], __('Sections'), __('Symphony'))));
			$this->appendSubheading($meta['name'],
				Widget::Anchor(__('View Entries'), SYMPHONY_URL . '/publish/' . $section->get('handle'), __('View Section Entries'), 'button')
			);
			$this->insertBreadcrumbs(array(
				Widget::Anchor(__('Sections'), SYMPHONY_URL . '/blueprints/sections/'),
			));

			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Essentials')));

			$div = new XMLElement('div', NULL, array('class' => 'two columns'));
			$namediv = new XMLElement('div', NULL, array('class' => 'column'));

			$label = Widget::Label(__('Name'));
			$label->appendChild(Widget::Input('meta[name]', General::sanitize($meta['name'])));

			if(isset($this->_errors['name'])) $namediv->appendChild(Widget::Error($label, $this->_errors['name']));
			else $namediv->appendChild($label);

			$label = Widget::Label();
			$input = Widget::Input('meta[hidden]', 'yes', 'checkbox', ($meta['hidden'] == 'yes' ? array('checked' => 'checked') : NULL));
			$label->setValue(__('%s Hide this section from the back-end menu', array($input->generate(false))));
			$namediv->appendChild($label);
			$div->appendChild($namediv);

			$navgroupdiv = new XMLElement('div', NULL, array('class' => 'column'));
			$sections = SectionManager::fetch(NULL, 'ASC', 'sortorder');
			$label = Widget::Label(__('Navigation Group'));
			$label->appendChild(Widget::Input('meta[navigation_group]', $meta['navigation_group']));

			if(isset($this->_errors['navigation_group'])) $navgroupdiv->appendChild(Widget::Error($label, $this->_errors['navigation_group']));
			else $navgroupdiv->appendChild($label);

			if(is_array($sections) && !empty($sections)){
				$ul = new XMLElement('ul', NULL, array('class' => 'tags singular'));
				$groups = array();
				foreach($sections as $s){
					if(in_array($s->get('navigation_group'), $groups)) continue;
					$ul->appendChild(new XMLElement('li', $s->get('navigation_group')));
					$groups[] = $s->get('navigation_group');
				}

				$navgroupdiv->appendChild($ul);
			}

			$div->appendChild($navgroupdiv);

			$fieldset->appendChild($div);

			$this->Form->appendChild($fieldset);

			/**
			 * Allows extensions to add elements to the header of the Section Editor
			 * form. Usually for section settings, this delegate is passed the current
			 * `$meta` array and the `$this->_errors` array.
			 *
			 * @delegate AddSectionElements
			 * @since Symphony 2.2
			 * @param string $context
			 * '/blueprints/sections/'
			 * @param XMLElement $form
			 *  An XMLElement of the current `$this->Form`, just after the Section
			 *  settings have been appended, but before the Fields duplicator
			 * @param array $meta
			 *  The current $_POST['meta'] array
			 * @param array $errors
			 *  The current errors array
			 */
			Symphony::ExtensionManager()->notifyMembers('AddSectionElements', '/blueprints/sections/', array(
				'form' => &$this->Form,
				'meta' => &$meta,
				'errors' => &$this->_errors
			));

			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Fields')));
			$p = new XMLElement('p', __('Click to expand or collapse a field.') . '<br />' . __('Double click to expand or collapse all fields.'), array('class' => 'help'));
			$fieldset->appendChild($p);

			$div = new XMLElement('div', null, array('class' => 'frame'));

			$ol = new XMLElement('ol');
			$ol->setAttribute('id', 'fields-duplicator');
			$ol->setAttribute('data-add', __('Add field'));
			$ol->setAttribute('data-remove', __('Remove field'));

			if(is_array($fields) && !empty($fields)){
				foreach($fields as $position => $field){

					$wrapper = new XMLElement('li', NULL, array('class' => 'field-' . $field->handle() . ($field->mustBeUnique() ? ' unique' : NULL)));
					$wrapper->setAttribute('data-type', $field->handle());

					$field->set('sortorder', $position);
					$field->displaySettingsPanel($wrapper, (isset($this->_errors[$position]) ? $this->_errors[$position] : NULL));
					$ol->appendChild($wrapper);

				}
			}

			foreach (FieldManager::listAll() as $type) {
				if ($type = FieldManager::create($type)) {
					array_push($types, $type);
				}
			}

			uasort($types, create_function('$a, $b', 'return strnatcasecmp($a->_name, $b->_name);'));

			foreach ($types as $type) {
				$defaults = array();

				$type->findDefaults($defaults);
				$type->setArray($defaults);

				$wrapper = new XMLElement('li');

				$wrapper->setAttribute('class', 'template field-' . $type->handle() . ($type->mustBeUnique() ? ' unique' : NULL));
				$wrapper->setAttribute('data-type', $type->handle());

				$type->set('sortorder', '-1');
				$type->displaySettingsPanel($wrapper);

				$ol->appendChild($wrapper);
			}

			$div->appendChild($ol);
			$fieldset->appendChild($div);

			$this->Form->appendChild($fieldset);

			$div = new XMLElement('div');
			$div->setAttribute('class', 'actions');
			$div->appendChild(Widget::Input('action[save]', __('Save Changes'), 'submit', array('accesskey' => 's')));

			$button = new XMLElement('button', __('Delete'));
			$button->setAttributeArray(array('name' => 'action[delete]', 'class' => 'button confirm delete', 'title' => __('Delete this section'), 'type' => 'submit', 'accesskey' => 'd', 'data-message' => __('Are you sure you want to delete this section?')));
			$div->appendChild($button);

			$div->appendChild(Widget::Input('meta[unique_hash]', $meta['unique_hash'], 'hidden'));

			$this->Form->appendChild($div);
		}

		public function __actionIndex(){

			$checked = (is_array($_POST['items'])) ? array_keys($_POST['items']) : null;

			if(is_array($checked) && !empty($checked)){

				if($_POST['with-selected'] == 'delete') {
					/**
					 * Just prior to calling the Section Manager's delete function
					 *
					 * @delegate SectionPreDelete
					 * @since Symphony 2.2
					 * @param string $context
					 * '/blueprints/sections/'
					 * @param array $section_ids
					 *  An array of Section ID's passed by reference
					 */
					Symphony::ExtensionManager()->notifyMembers('SectionPreDelete', '/blueprints/sections/', array('section_ids' => &$checked));

					foreach($checked as $section_id) SectionManager::delete($section_id);

					redirect(SYMPHONY_URL . '/blueprints/sections/');
				}

				else if($_POST['with-selected'] == 'delete-entries') {
					foreach($checked as $section_id) {
						$entries = EntryManager::fetch(NULL, $section_id, NULL, NULL, NULL, NULL, false, false, null, false);
						$entry_ids = array();
						foreach($entries as $entry) {
							$entry_ids[] = $entry['id'];
						}

						/**
						 * Prior to deletion of entries.
						 *
						 * @delegate Delete
						 * @param string $context
						 * '/publish/'
						 * @param array $entry_id
						 *  An array of Entry ID's that are about to be deleted, passed by reference
						 */
						Symphony::ExtensionManager()->notifyMembers('Delete', '/publish/', array('entry_id' => &$entry_ids));

						EntryManager::delete($entry_ids, $section_id);
					}

					redirect(SYMPHONY_URL . '/blueprints/sections/');
				}

				else if(preg_match('/^set-navigation-group-/', $_POST['with-selected'])) {
					$navigation_group = preg_replace('/^set-navigation-group-/', null, $_POST['with-selected']);

					foreach($checked as $section_id) {
						SectionManager::edit($section_id, array('navigation_group' => urldecode($navigation_group)));
					}

					redirect(SYMPHONY_URL . '/blueprints/sections/');
				}
			}

		}

		public function __actionNew(){
			if(@array_key_exists('save', $_POST['action']) || @array_key_exists('done', $_POST['action'])) {

				$canProceed = true;
				$edit = ($this->_context[0] == "edit");
				$this->_errors = array();

				$fields = $_POST['fields'];
				$meta = $_POST['meta'];


				if($edit) {
					$section_id = $this->_context[1];
					$existing_section = SectionManager::fetch($section_id);
				}

				// Check to ensure all the required section fields are filled
				if(!isset($meta['name']) || strlen(trim($meta['name'])) == 0){
					$required = array('Name');
					$this->_errors['name'] = __('This is a required field.');
					$canProceed = false;
				}

				// Check for duplicate section handle
				elseif($edit) {
					if(
						$meta['name'] != $existing_section->get('name')
						&& $s = SectionManager::fetchIDFromHandle(Lang::createHandle($meta['name']))
						&& !is_null($s) && $s != $section_id
					) {
						$this->_errors['name'] = __('A Section with the name %s already exists', array('<code>' . $meta['name'] . '</code>'));
						$canProceed = false;
					}
				}
				elseif(!is_null(SectionManager::fetchIDFromHandle(Lang::createHandle($meta['name'])))) {
					$this->_errors['name'] = __('A Section with the name %s already exists', array('<code>' . $meta['name'] . '</code>'));
					$canProceed = false;
				}

				// Check to ensure all the required section fields are filled
				if(!isset($meta['navigation_group']) || strlen(trim($meta['navigation_group'])) == 0){
					$required = array('Navigation Group');
					$this->_errors['navigation_group'] = __('This is a required field.');
					$canProceed = false;
				}

				// Basic custom field checking
				if(is_array($fields) && !empty($fields)){
					// Check for duplicate CF names
					if($canProceed) {
						$name_list = array();

						foreach($fields as $position => $data){
							if(trim($data['element_name']) == '') {
								$data['element_name'] = $fields[$position]['element_name'] = $_POST['fields'][$position]['element_name'] = Lang::createHandle($data['label'], 255, '-', false, true, array('@^[\d-]+@i' => ''));
							}

							if(trim($data['element_name']) != '' && in_array($data['element_name'], $name_list)){
								$this->_errors[$position] = array('element_name' => __('A field with this handle already exists. All handle must be unique.'));
								$canProceed = false;
								break;
							}

							$name_list[] = $data['element_name'];
						}
					}

					if($canProceed) {
						$unique = array();

						foreach($fields as $position => $data){
							$required = NULL;

							$field = FieldManager::create($data['type']);
							$field->setFromPOST($data);

							if($existing_section) {
								$field->set('parent_section', $existing_section->get('id'));
							}

							if($field->mustBeUnique() && !in_array($field->get('type'), $unique)) $unique[] = $field->get('type');
							elseif($field->mustBeUnique() && in_array($field->get('type'), $unique)){
								// Warning. cannot have 2 of this field!
								$canProceed = false;
								$this->_errors[$position] = array('label' => __('There is already a field of type %s. There can only be one per section.', array('<code>' . $field->handle() . '</code>')));
							}

							$errors = array();

							if(Field::__OK__ != $field->checkFields($errors, false) && !empty($errors)){
								$this->_errors[$position] = $errors;
								$canProceed = false;
							}
						}
					}
				}

				if($canProceed){
					$meta['handle'] = Lang::createHandle($meta['name']);
					// $meta['fields'] = $fields;

					// If we are creating a new Section
					if(!$edit) {

						$meta['sortorder'] = SectionManager::fetchNextSortOrder();

						/**
						 * Just prior to saving the Section settings. Use with caution as
						 * there is no additional processing to ensure that Field's or Section's
						 * are unique.
						 *
						 * @delegate SectionPreCreate
						 * @since Symphony 2.2
						 * @param string $context
						 * '/blueprints/sections/'
						 * @param array $meta
						 *  The section's settings, passed by reference
						 * @param array $fields
						 *  An associative array of the fields that will be saved to this
						 *  section with the key being the position in the Section Editor
						 *  and the value being a Field object, passed by reference
						 */
						Symphony::ExtensionManager()->notifyMembers('SectionPreCreate', '/blueprints/sections/', array('meta' => &$meta, 'fields' => &$fields));

						if(!$section_id = SectionManager::add($meta)){
							$this->pageAlert(__('An unknown database occurred while attempting to create the section.'), Alert::ERROR);
						}
					}

					// We are editing a Section
					else {
						$meta['hidden'] = (isset($meta['hidden']) ? 'yes' : 'no');

						/**
						 * Just prior to updating the Section settings. Use with caution as
						 * there is no additional processing to ensure that Field's or Section's
						 * are unique.
						 *
						 * @delegate SectionPreEdit
						 * @since Symphony 2.2
						 * @param string $context
						 * '/blueprints/sections/'
						 * @param integer $section_id
						 *  The Section ID that is about to be edited.
						 * @param array $meta
						 *  The section's settings, passed by reference
						 * @param array $fields
						 *  An associative array of the fields that will be saved to this
						 *  section with the key being the position in the Section Editor
						 *  and the value being a Field object, passed by reference
						 */
						Symphony::ExtensionManager()->notifyMembers('SectionPreEdit', '/blueprints/sections/', array('section_id' => $section_id, 'meta' => &$meta, 'fields' => &$fields));

						if(!SectionManager::edit($section_id, $meta)){
							$canProceed = false;
							$this->pageAlert(__('An unknown database occurred while attempting to create the section.'), Alert::ERROR);
						}
					}

					if($section_id && $canProceed) {
						if($edit) {
							// Delete missing CF's
							$id_list = array();
							if(is_array($fields) && !empty($fields)){
								foreach($fields as $position => $data){
									if(isset($data['id'])) $id_list[] = $data['id'];
								}
							}

                            // This variable represents the fields that occur in the database but are not sent by the browser.
                            // This happens when editing a section and the user removes a field and saves the section. These 'missing fields'
                            // need to be removed from the database.
                            $missing_cfs = FieldManager::fetchRemovedFieldsFromSection($section_id, $id_list, true);

							if(is_array($missing_cfs) && !empty($missing_cfs)){
								foreach($missing_cfs as $id){
									FieldManager::delete($id);
								}
							}
						}

						// Save each custom field
						if(is_array($fields) && !empty($fields)){
							foreach($fields as $position => $data){
								$field = FieldManager::create($data['type']);
								$field->setFromPOST($data);
								$field->set('sortorder', (string)$position);
								$field->set('parent_section', $section_id);

								$newField = !(boolean)$field->get('id');

								$field->commit();
								$field_id = $field->get('id');

								if($field_id) {
									if($newField) {
										/**
										 * After creation of a Field.
										 *
										 * @delegate FieldPostCreate
										 * @param string $context
										 * '/blueprints/sections/'
										 * @param Field $field
										 *  The Field object, passed by reference
										 * @param array $data
										 *  The settings for ths `$field`, passed by reference
										 */
										Symphony::ExtensionManager()->notifyMembers('FieldPostCreate', '/blueprints/sections/', array('field' => &$field, 'data' => &$data));
									}
									else {
										/**
										 * After editing of a Field.
										 *
										 * @delegate FieldPostEdit
										 * @param string $context
										 * '/blueprints/sections/'
										 * @param Field $field
										 *  The Field object, passed by reference
										 * @param array $data
										 *  The settings for ths `$field`, passed by reference
										 */
										Symphony::ExtensionManager()->notifyMembers('FieldPostEdit', '/blueprints/sections/', array('field' => &$field, 'data' => &$data));
									}
								}
							}
						}

						if(!$edit) {
							/**
							 * After the Section has been created, and all the Field's have been
							 * created for this section, but just before the redirect
							 *
							 * @delegate SectionPostCreate
							 * @since Symphony 2.2
							 * @param string $context
							 * '/blueprints/sections/'
							 * @param integer $section_id
							 *  The newly created Section ID.
							 */
							Symphony::ExtensionManager()->notifyMembers('SectionPostCreate', '/blueprints/sections/', array('section_id' => $section_id));

							redirect(SYMPHONY_URL . "/blueprints/sections/edit/$section_id/created/");
						}
						else {
							/**
							 * After the Section has been updated, and all the Field's have been
							 * updated for this section, but just before the redirect
							 *
							 * @delegate SectionPostEdit
							 * @since Symphony 2.2
							 * @param string $context
							 * '/blueprints/sections/'
							 * @param integer $section_id
							 *  The edited Section ID.
							 */
							Symphony::ExtensionManager()->notifyMembers('SectionPostEdit', '/blueprints/sections/', array('section_id' => $section_id));

							redirect(SYMPHONY_URL . "/blueprints/sections/edit/$section_id/saved/");

						}
					}
				}
			}

			if(@array_key_exists("delete", $_POST['action'])){
				$section_id = array($this->_context[1]);

				/**
				 * Just prior to calling the Section Manager's delete function
				 *
				 * @delegate SectionPreDelete
				 * @since Symphony 2.2
				 * @param string $context
				 * '/blueprints/sections/'
				 * @param array $section_ids
				 *  An array of Section ID's passed by reference
				 */
				Symphony::ExtensionManager()->notifyMembers('SectionPreDelete', '/blueprints/sections/', array('section_ids' => &$section_id));

				foreach($section_id as $section) SectionManager::delete($section);
				redirect(SYMPHONY_URL . '/blueprints/sections/');
			}
		}

		public function __actionEdit(){
			return $this->__actionNew();
		}

		/**
		 * This screen shows the differences between the cached index and the local index
		 * and offers the options to accept the changes or reject the changes.
		 */
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
							Administration::instance()->Page->pageAlert(__('Sections modifications are successfully accepted.'), Alert::SUCCESS);
							redirect(SYMPHONY_URL.'/blueprints/sections/');
							break;
						}
					case 'reject' :
						{
							$this->__rejectDiff();
							/* Todo: Show the notice after the redirect: */
							Administration::instance()->Page->pageAlert(__('Sections modifications are successfully rejected.'), Alert::SUCCESS);
							redirect(SYMPHONY_URL.'/blueprints/sections/');
							break;
						}
					default:
						{
							// Invalid URL, redirect to diff screen:
							redirect(SYMPHONY_URL.'/blueprints/sections/diff/');
							break;
						}
				}
			}

			$this->setTitle(__('%1$s &ndash; %2$s', array(__('Section Differences'), __('Symphony'))));
			$this->addStylesheetToHead(SYMPHONY_URL.'/assets/css/symphony.diff.css');

			// Create the head:
			$tableHead = Widget::TableHead(array(
				array(__('Section')),
				array(__('Changes')),
			));

			// Get the indexes:
			$cachedIndex = SectionManager::index()->getIndex();
			$localIndex  = SectionManager::index()->getLocalIndex();

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
				$foundSections = array();

				// Flag if an error is found (and changes cannot be accepted):
				$error = false;

				// Check the cached sections:
				foreach($cachedIndex->xpath('section') as $cachedSection)
				{
					$rowClass = null;

					// Check the differences:
					$localSection = $localIndex->xpath(
						sprintf('section[unique_hash=\'%s\']', (string)$cachedSection->unique_hash)
					);
					if(count($localSection) == 1)
					{
						$localSection = $localSection[0];
						// Section found in local index, check for differences:
						$cachedRow = new XMLElement('td', (string)$cachedSection->name);
						// Check if the parsed XML is identical:
						if($cachedSection->saveXML() == $localSection->saveXML())
						{
							$localRow = new XMLElement('td', __('No changes found.'));
							$rowClass = 'no-changes';
						} else {
							// Validate local section first:
							$result = $this->__validateSection($localSection, $localIndex);
							if($result !== true)
							{
								// Section does not validate:
								$localRow = new XMLElement('td', $result);
								$error = true;
								$rowClass = 'error';
							} else {
								// Section validates, continue:
								$localRow = new XMLElement('td', __('Section is modified:'));
								// Show changes:
								$changes = new XMLElement('ul');
								foreach($cachedSection->children() as $cachedElement)
								{
									// Iterate through each element to detect changes:
									$name = $cachedElement->getName();
									switch($name)
									{
										case 'fields' :
											{
												// The fieldnode requires some special attention:
												$foundFields  = array(); // Keep track of the found fields to determine if fields are going to be added:
												$cachedFields = $cachedSection->xpath('fields/field');
												foreach($cachedFields as $cachedField)
												{
													// Check to see if the field exists locally:
													$localFields = $localSection->xpath(
														sprintf('fields/field[unique_hash=\'%s\']', (string)$cachedField->unique_hash)
													);
													if(count($localFields) == 1)
													{
														// Field found, check for differences:
														$localField = $localFields[0];
														if($cachedField->saveXML() != $localField->saveXML())
														{
															// Field is changed:
															$li = new XMLElement('li', sprintf(__('Field <em>\'%s\'</em> is changed:'), (string)$cachedField->label));
															$ul = new XMLElement('ul');

															// Check if the field validates:
															$result = $this->__validateField($localField, $localIndex);
															if($result !== true)
															{
																// Field doesn't validate:
																$ul->appendChild(new XMLElement('li', $result));
																$error = true;
																$rowClass = 'error';
															} else {
																// Field validates, show the differences:
																// Iterate through the elements:
																foreach($localField->children() as $localFieldElement)
																{
																	// Check if this element exists in the local field:
																	$localFieldElementName = $localFieldElement->getName();
																	$cachedFieldElements = $cachedField->xpath($localFieldElementName);
																	if(count($cachedFieldElements) == 1)
																	{
																		// Field element found, check for differences:
																		$cachedFieldElement = $cachedFieldElements[0];
																		if($localFieldElement->saveXML() != $cachedFieldElement->saveXML())
																		{
																			// Difference found:
																			// Show the difference:
																			$ul->appendChild(
																				new XMLElement('li', sprintf(__('Field element <em>\'%s\'</em> :  %s → %s'),
																					$localFieldElementName,
																					(string)$cachedFieldElement,
																					(string)$localFieldElement))
																			);
																		}
																	} else {
																		// Field setting not found: this setting will be added:
																		$ul->appendChild(
																			new XMLElement('li', sprintf(__('Field setting <em>\'%s\'</em> will be added.'),
																				$localFieldElementName))
																		);
																	}
																}
															}
															$li->appendChild($ul);
															$changes->appendChild($li);
														}
													} elseif(count($localFields) > 1) {
														// Fields with duplicate hashes found, this is not allowed:
														$changes->appendChild(
															new XMLElement('li', sprintf(__('Duplicate hash found for field <em>\'%s\'</em> (%s). Changes cannot be accepted.'),
																(string)$cachedField->label,
																(string)$cachedField->unique_hash
															))
														);
														$error = true;
														$rowClass = 'error';
													} else {
														// Local field not found, this field is going to be deleted:
														$changes->appendChild(
															new XMLElement('li', sprintf(__('Field <em>\'%s\'</em> (including it\'s data) is going to be deleted.'),
																(string)$cachedField->label))
														);
														$rowClass = 'alert';
													}
													$foundFields[] = (string)$cachedField->unique_hash;
												}

												// Check the local fields (to see if there are fields added):
												foreach($localSection->xpath('fields/field') as $localField)
												{
													if(!in_array((string)$localField->unique_hash, $foundFields))
													{
														// This is a new field for this section.
														// Check if the field validates:
														$result = $this->__validateField($localField, $localIndex);
														if($result !== true)
														{
															// Field doesn't validate:
															$changes->appendChild(new XMLElement('li', $result));
															$error = true;
															$rowClass = 'error';
														} else {
															// Field validates:
															// Check if this section doesn't already have a field with this handle:
															if(count($localSection->xpath(sprintf('fields/field[element_name=\'%s\']', (string)$localField->element_name))) == 1)
															{
																$changes->appendChild(
																	new XMLElement('li', sprintf(__('Field <em>\'%s\'</em> is new and will be added to the section.'),
																		(string)$localField->label))
																);
																// $rowClass = 'notice';
															} else {
																// There already exists a field with this handle:
																$changes->appendChild(
																	new XMLElement('li', sprintf(__('This section already has a field with element name <em>\'%s\'</em>.'),
																		(string)$localField->element_name))
																);
																$error = true;
																$rowClass = 'error';
															}
														}
													}
												}
												break;
											}
										default :
											{
												// All other nodes:
												// Check if there are differences:
												$localElements = $localSection->xpath($name);
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
												break;
											}
									}
								}
								$localRow->appendChild($changes);
							}
						}
					} elseif(count($localSection) > 1) {
						// Section with duplicate hashes found. This is not allowed:
						$cachedRow = new XMLElement('td', (string)$cachedSection->name);
						$localRow  = new XMLElement('td', __('Duplicate hash found for this section.'));
						$error = true;
						$rowClass = 'error';
					} else {
						// Section not found in local index, section is going to be deleted:
						$cachedRow = new XMLElement('td', (string)$cachedSection->name);
						$localRow  = new XMLElement('td', __('The section is not found in the local index. This sections is going to be deleted'));
						$rowClass = 'alert';
					}
					$foundSections[] = (string)$cachedSection->unique_hash;

					$tableRows[] = Widget::TableRow(array($cachedRow, $localRow), $rowClass);
				}

				// Check the local sections (to see if there are sections added):
				foreach($localIndex->xpath('section') as $localSection)
				{
					$rowClass = null;
					if(!in_array((string)$localSection->unique_hash, $foundSections))
					{
						$cachedRow = new XMLElement('td', (string)$localSection->name);
						$ok = true;
						// Validate the section:
						$result = $this->__validateSection($localSection, $localIndex);
						if($result !== true)
						{
							// Section does not validate:
							$localRow = new XMLElement('td', $result);
							$error = true;
							$rowClass = 'error';
						} else {
							// Validate the fields of the section:
							$fieldErrors = new XMLElement('ul');
							foreach($localSection->xpath('fields/field') as $localField)
							{
								// Check if the field validates:
								$result = $this->__validateField($localField, $localIndex);
								if($result !== true)
								{
									// Field doesn't validate:
									$fieldErrors->appendChild(new XMLElement('li', $result));
									$error = true;
									$ok = false;
									$rowClass = 'error';
								}
							}
							if(!$ok)
							{
								$localRow = new XMLElement('td', __('This section cannot be added because of the following problems:'));
								$localRow->appendChild($fieldErrors);
							} else {
								// Everything is just fine!
								$localRow = new XMLElement('td', __('This section is new and will be created.'));
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
				$list->appendChild(new XMLElement('li', Widget::Anchor(__('Accept Changes'), SYMPHONY_URL.'/blueprints/sections/diff/accept/', __('Accept Changes'), 'create button', NULL, array('accesskey' => 'a'))));
				$list->appendChild(new XMLElement('li', Widget::Anchor(__('Reject Changes'), SYMPHONY_URL.'/blueprints/sections/diff/reject/', __('Reject Changes'), 'button', NULL, array('accesskey' => 'r'))));
				// $this->appendSubheading(__('Section Differences'), Widget::Anchor(__('Accept Changes'), Administration::instance()->getCurrentPageURL().'accept/', __('Accept Changes'), 'create button', NULL, array('accesskey' => 'a')));
				$this->appendSubheading(__('Section Differences'));
				$this->Context->appendChild($list);
			} else {
				$this->Contents->appendChild(new XMLElement('p', __('The changes cannot be accepted for one ore more reasons. Please see the report below to find out what\'s wrong:'), array('class'=>'diff-notice')));
				$this->appendSubheading(__('Section Differences'), Widget::Anchor(__('Reject Changes'), SYMPHONY_URL.'/blueprints/sections/diff/reject/', __('Reject Changes'), 'button', NULL, array('accesskey' => 'r')));
			}
			$this->Contents->appendChild($table);
		}

		/**
		 * Validate the section
		 *
		 * @param $sectionElement
		 *  The section element
		 * @param $index
		 *  The index for additional testing
		 * @return bool|string
		 *  Returns true on success or an error message on failure.
		 */
		private function __validateSection($sectionElement, $index)
		{
			// First check if all the required elements are there:
			$requiredElements = array('name', 'sortorder', 'hidden', 'navigation_group', 'unique_hash');
			foreach($requiredElements as $elementName)
			{
				if((string)$sectionElement->$elementName == '')
				{
					return sprintf(__('Required element \'%s\' is not found. Changes cannot be accepted.'), $elementName);
				}
			}
			// Check hidden:
			if((string)$sectionElement->hidden != 'yes' && (string)$sectionElement->hidden != 'no')
			{
				return __('<em>\'hidden\'</em> must be either <em>\'yes\'</em> or <em>\'no\'</em>.');
			}
			// Check sortorder:
			if(!is_numeric((string)$sectionElement->sortorder))
			{
				return __('<em>\'sortorder\'</em> must be numeric.');
			}
			// Check if the sections' handle matches it's name:
			if((string)$sectionElement->name['handle'] != General::createHandle((string)$sectionElement->name))
			{
				return sprintf(__('Invalid handle. The handle must be <em>\'%s\'</em>.'),
					General::createHandle((string)$sectionElement->name));
			}
			// Check if the sections XML file matches it's handle:
			if(!file_exists(WORKSPACE.'/sections/'.General::createHandle((string)$sectionElement->name).'.xml'))
			{
				return sprintf(__('Invalid filename. The filename must be <em>\'%s.xml\'</em>.'),
					General::createHandle((string)$sectionElement->name));
			} else {
				// Extra check to make sure that this is the correct XML-file (since we are working with the
				// index, we don't know the filename by hand:
				$xml = simplexml_load_file(WORKSPACE.'/sections/'.General::createHandle((string)$sectionElement->name).'.xml');
				if((string)$xml->unique_hash != (string)$sectionElement->unique_hash)
				{
					return sprintf(__('Invalid filename. The filename must be <em>\'%s.xml\'</em>.'),
						General::createHandle((string)$sectionElement->name));
				}
			}
			// Check if the hash of the section is unique:
			if(count($index->xpath(sprintf('section[unique_hash=\'%s\']', (string)$sectionElement->unique_hash))) > 1)
			{
				return __('Duplicate hash found for this section.');
			}
			// Check if the section name is unique:
			if(count($index->xpath(sprintf('section[name=\'%s\']', (string)$sectionElement->name))) > 1)
			{
				return __('There already exists a section with this name.');
			}
			// Everything seems ok from here...
			return true;
		}

		/**
		 * Validate a single field element
		 *
		 * @param $fieldElement
		 *  The fieldelement
		 * @param $index
		 *  The index for additional testing
		 * @return bool|string
		 *  True on success, or the error message on failure.
		 */
		private function __validateField($fieldElement, $index)
		{
			// First check if all the required elements are there:
			$requiredElements = array('label', 'element_name', 'unique_hash', 'type', 'required', 'sortorder', 'location', 'show_column');
			foreach($requiredElements as $elementName)
			{
				if((string)$fieldElement->$elementName == '')
				{
					return sprintf(__('Required element \'%s\' is not found. Changes cannot be accepted.'), $elementName);
				}
			}
			// Validate element_name:
			if(!preg_match('/^[a-z][-a-z0-9]*$/', (string)$fieldElement->element_name))
			{
				return sprintf(__('The element name <em>\'%s\'</em> is invalid.'), (string)$fieldElement->element_name);
			}
			// Check type:
			$availableFieldTypes = FieldManager::listAll();
			if(!in_array((string)$fieldElement->type, $availableFieldTypes))
			{
				return sprintf(__('Field <em>\'%s\'</em> cannot be installed for this section. Install the field type first :  <em>\'%s\'</em>'),
						(string)$fieldElement->label,
						(string)$fieldElement->type);
			}
			// Check required:
			if((string)$fieldElement->required != 'yes' && (string)$fieldElement->required != 'no')
			{
				return __('<em>\'required\'</em> must be either <em>\'yes\'</em> or <em>\'no\'</em>.');
			}
			// Check sortorder:
			if(!is_numeric((string)$fieldElement->sortorder))
			{
				return __('<em>\'sortorder\'</em> must be numeric.');
			}
			// Check show_column:
			if((string)$fieldElement->show_column != 'yes' && (string)$fieldElement->show_column != 'no')
			{
				return __('<em>\'show_column\'</em> must be either <em>\'yes\'</em> or <em>\'no\'</em>.');
			}
			// Check show_column:
			if((string)$fieldElement->location != 'main' && (string)$fieldElement->location != 'sidebar')
			{
				return __('<em>\'location\'</em> must be either <em>\'main\'</em> or <em>\'sidebar\'</em>.');
			}
			// Check if the field hashes are unique:
			$localFields = $index->xpath(sprintf('section/fields/field[unique_hash=\'%s\']', (string)$fieldElement->unique_hash));
			if(count($localFields) > 1 && $localFields != false)
			{
				return sprintf(__('Field <em>\'%s\'</em> does not have a unique hash.'), (string)$fieldElement->label);
			}
			// Check if there are no duplicate fields in this section:
			if(count($index->xpath(sprintf('fields/field[element_name=\'%s\']', (string)$fieldElement->element_name))) > 1)
			{
				return sprintf(__('Field <em>\'%s\'</em> occurs more than once.'), (string)$fieldElement->label);
			}

			return true;
		}

		/**
		 * Function to accept the diff. Use the local XML files to edit the sections
		 */
		private function __acceptDiff()
		{
			// Get the indexes:
			$cachedIndex = SectionManager::index()->getIndex();
			$localIndex  = SectionManager::index()->getLocalIndex();

			// Array to keep track of the sections that are already found:
			$foundSections = array();

			// Check the cached sections:
			foreach($cachedIndex->xpath('section') as $cachedSection)
			{
				// Check the differences:
				$localSection = $localIndex->xpath(
					sprintf('section[unique_hash=\'%s\']', (string)$cachedSection->unique_hash)
				);
				if(count($localSection) == 1)
				{
					// This sections is found, edit it according to it's local section:
					$localSection = $localSection[0];
					SectionManager::edit(
						SectionManager::lookup()->getId((string)$cachedSection->unique_hash),
						array(
							'name' 				=> (string)$localSection->name,
							'handle' 			=> (string)$localSection->name['handle'],
							'sortorder' 		=> (string)$localSection->sortorder,
							'hidden' 			=> (string)$localSection->hidden,
							'navigation_group' 	=> (string)$localSection->navigation_group
						)
					);

					$foundFields = array();

					// Edit the section fields according to it's local section fields:
					foreach($cachedSection->xpath('fields/field') as $cachedField)
					{
						// See if there is a local field that corresponds with the cached field:
						$localFields = $localSection->xpath(
							sprintf('fields/field[unique_hash=\'%s\']', (string)$cachedField->unique_hash)
						);
						if(count($localFields) == 1)
						{
							// Field found, edit it according to it's local brother:
							$localField = $localFields[0];
							$fieldElements = array();
							foreach($cachedField->children() as $cachedFieldElement)
							{
								$localValues = $localField->xpath($cachedFieldElement->getName());
								$fieldElements[$cachedFieldElement->getName()] = (string)$localValues[0];
							}

							FieldManager::edit(
								FieldManager::lookup()->getId((string)$cachedField->unique_hash),
								$fieldElements
							);
						} else {
							// Field not found in local index, field is going to be deleted:
							FieldManager::delete(
								FieldManager::lookup()->getId((string)$cachedField->unique_hash)
							);
						}
						$foundFields[] = (string)$cachedField->unique_hash;
					}
					// See if there are fields that need to be added to this section:
					foreach($localSection->xpath('fields/field') as $localSectionField)
					{
						if(!in_array((string)$localSectionField->unique_hash, $foundFields))
						{
							// Field was not found in the cached index, so it's a new field:
							$fieldSettings = array(
								'parent_section' => SectionManager::lookup()->getId(
									(string)$localSection->unique_hash
								)
							);

							// Add the fields:
							foreach($localSectionField->children() as $localFieldElement)
							{
								$fieldSettings[$localFieldElement->getName()] = (string)$localFieldElement;
							}

							// Add (and thus saving) the field:
							$id = FieldManager::add($fieldSettings);
							// Create the data table of the field:
							$field = FieldManager::fetch($id);
							$field->createTable();
						}
					}

				} else {
					// Section not found in local index, section is going to be deleted:
					SectionManager::delete(
						SectionManager::lookup()->getId((string)$cachedSection->unique_hash)
					);
				}
				$foundSections[] = (string)$cachedSection->unique_hash;
			}

			// Check the local sections (to see if there are sections added):
			foreach($localIndex->xpath('section') as $localSection)
			{
				if(!in_array((string)$localSection->unique_hash, $foundSections))
				{
					$sectionSettings = array(
						'name' 				=> (string)$localSection->name,
						'handle' 			=> (string)$localSection->name['handle'],
						'sortorder' 		=> (string)$localSection->sortorder,
						'hidden' 			=> (string)$localSection->hidden,
						'navigation_group' 	=> (string)$localSection->navigation_group,
						'unique_hash'		=> (string)$localSection->unique_hash
					);
					// This is a new section, add it:
					SectionManager::add(
						$sectionSettings
					);
					// Add the fields:
					foreach($localSection->xpath('fields/field') as $localSectionField)
					{
						$fieldSettings = array(
							'parent_section' => SectionManager::lookup()->getId(
								(string)$localSection->unique_hash
							)
						);

						// Add the fields:
						foreach($localSectionField->children() as $localFieldElement)
						{
							$fieldSettings[$localFieldElement->getName()] = (string)$localFieldElement;
						}

						// Add (and thus saving) the field:
						$id = FieldManager::add($fieldSettings);
						// Create the data table of the field:
						$field = FieldManager::fetch($id);
						$field->createTable();
					}
				}
			}
		}

		/**
		 * Reject the diff. Use the cached XML tree to re-generate the section XML files.
		 */
		private function __rejectDiff()
		{
			// Delete all local section XML files:
			$files = glob(WORKSPACE.'/sections/*.xml');
			foreach($files as $file)
			{
				General::deleteFile($file);
			}

			// Store the cached sections as new XML files:
			$index = SectionManager::index()->getIndex();
			foreach($index->children() as $section)
			{
				// Save the section, without reIndexing. We'll reIndex manually after saving all the sections:
				SectionManager::saveSection(
					SectionManager::lookup()->getId((string)$section->unique_hash), false
				);
			}

			// Clear the cache and reIndex:
			SectionManager::index()->reIndex();
		}

	}
