<?php

	/**
	 * @package data-sources
	 */
	/**
	 * The `NavigationDatasource` outputs the Symphony page structure as XML.
	 * This datasource supports filtering to narrow down the results to only
	 * show pages that match a particular page type, have a specific parent, etc.
	 *
	 * @since Symphony 2.3
	 */
	Class NavigationDatasource extends Datasource{

		public function __processNavigationParentFilter($parent){
			$parent_paths = preg_split('/,\s*/', $parent, -1, PREG_SPLIT_NO_EMPTY);
			$parent_paths = array_map(create_function('$a', 'return trim($a, " /");'), $parent_paths);

			$xpath = '(';
			$first = true;
			foreach($parent_paths as $path) {
				if(!$first) { $xpath .= ' or '; }
				$xpath .= 'path=\''.$path.'\'';
				$first = false;
			}
			$xpath .= ')';

			return $xpath;
		}

		public function __processNavigationTypeFilter($filter, $filter_type = DS_FILTER_OR) {
			$types = preg_split('/'.($filter_type == DS_FILTER_AND ? '\+' : '(?<!\\\\),').'\s*/', $filter, -1, PREG_SPLIT_NO_EMPTY);
			$types = array_map('trim', $types);

			$types = array_map(array('Datasource', 'removeEscapedCommas'), $types);

			if($filter_type == DS_FILTER_OR) {
				$xpath = '(';
				$first = true;
				foreach($types as $type) {
					if(!$first) { $xpath .= ' or '; }
					$xpath .= 'types/type=\''.$type.'\'';
					$first = false;
				}
				$xpath .= ')';
			}
			else {
				$xpath = '';
				$first = true;
				foreach($types as $type) {
					if(!$first) { $xpath .= ' and '; }
					$xpath .= 'types/type=\''.$type.'\'';
					$first = false;
				}
			}

			return $xpath;
		}

		public function __buildPageXML($page, $page_types) {
			$oPage = new XMLElement('page');
			$oPage->setAttribute('handle', $page['handle']);
			$oPage->setAttribute('id', $page['id']);
			$oPage->setAttribute('hash', $page['unique_hash']);
			$oPage->appendChild(new XMLElement('name', General::sanitize($page['title'])));

			if(in_array($page['id'], array_keys($page_types))) {
				$xTypes = new XMLElement('types');
				foreach($page_types[$page['id']] as $type) {
					$xTypes->appendChild(new XMLElement('type', $type));
				}
				$oPage->appendChild($xTypes);
			}

			if($page['children'] != '0') {
				if($children = PageManager::fetchByXPath(
					sprintf('page[parent=\'%s\']', PageManager::lookup()->getHash($page['id'])))
				) {
					foreach($children as $c) $oPage->appendChild($this->__buildPageXML($c, $page_types));
				}
			}

			return $oPage;
		}

		public function execute(&$param_pool) {
			$result = new XMLElement($this->dsParamROOTELEMENT);

			$xpath = 'page';
			$closebracket = false;
			if(trim($this->dsParamFILTERS['type']) != '') {
				$closebracket = true;
				$xpath .= '[';
				$xpath .= $this->__processNavigationTypeFilter($this->dsParamFILTERS['type'], $this->__determineFilterType($this->dsParamFILTERS['type']));
			}

			if(trim($this->dsParamFILTERS['parent']) != '') {
				if(!$closebracket) {
					$closebracket = true;
					$xpath .= '[';
				} else {
					$xpath .= ' and ';
				}
				$xpath .= $this->__processNavigationParentFilter($this->dsParamFILTERS['parent']);
			}
			if($closebracket) { $xpath .= ']'; }

			$pages = PageManager::fetchByXPath($xpath);

			if((!is_array($pages) || empty($pages))){
				if($this->dsParamREDIRECTONEMPTY == 'yes'){
					throw new FrontendPageNotFoundException;
				}
				$result->appendChild($this->__noRecordsFound());
			}

			else {
				// Build an array of all the types so that the page's don't have to do
				// individual lookups.
				$page_types = PageManager::fetchAllPagesPageTypes();

				foreach($pages as $page) {
					$result->appendChild($this->__buildPageXML($page, $page_types));
				}
			}

			return $result;
		}
	}
