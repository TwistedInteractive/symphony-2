<html>
<head>
	<title>Symphony Migration</title>
	<style type="text/css">
		html {
			font-family: monospace;
			background: #333;
			color: #0f0;
		}

		body {
			/*width: 640px;*/
		}

		a {
			color: #fff;
		}

		a:hover {
			color: #333;
			background: #0f0;
		}

		.error {
			color: #f33;
			font-weight: bold;
		}

		.notice {
			color: #fff;
		}
	</style>
</head>
<body>
<?php
/**
 * Simple database class
 * (c) 2012
 * Author: Giel Berkers
 * Date: 5-3-12
 * Time: 14:24
 */

class Db
{
	/**
	 * Connect to database
	 * @param $database
	 * @param $username
	 * @param $password
	 * @param string $host
	 */
	function connect($database, $username, $password, $host = 'localhost')
	{
		mysql_connect($host, $username, $password);
		mysql_select_db($database);
	}

	/**
	 * Run a query
	 * @param $sql
	 * @return resource
	 */
	function mq($sql)
	{
		$result = mysql_query($sql) or die(mysql_error() . ' : ' . $sql);
		return $result;
	}

	/**
	 * Get an associated array
	 * @param $sql
	 * @return array
	 */
	function ma($sql)
	{
		$r = $this->mq($sql);
		return mysql_fetch_assoc($r);
	}

	/**
	 * Get a single result
	 * @param $sql
	 * @return mixed
	 */
	function sr($sql)
	{
		$r = $this->mq($sql);
		$row = mysql_fetch_row($r);
		return $row[0];
	}
}

// Functions:
function put($str, $br = true, $line = false, $class = '')
{
	if(!empty($class)) { echo '<span class="'.$class.'">'; }
	echo $str;
	if(!empty($class)) { echo '</span>'; }
	if ($br) echo '<br />';
	if ($line) {
		echo '--------------------------------------------------------------------------------<br />';
	}
}

function choose($str, $options)
{
	echo '<br />' . $str . ' ';
	foreach ($options as $key => $value) {
		echo '[<a href="?step=' . $value . '">' . $key . '</a>] ';
	}
	echo '<br />';
}



// Database:
require_once('manifest/config.php');
$db = new Db();
$db->connect($settings['database']['db'], $settings['database']['user'], $settings['database']['password'],
	$settings['database']['host'] . ':' . $settings['database']['port']);
$pf = $settings['database']['tbl_prefix'];

put('Symphony Migration', true, true);

$step = isset($_GET['step']) ? $_GET['step'] : 0;

if(file_exists('migration_complete') && $step != 4) { header('Location: migrate.php?step=4'); }

switch ($step) {
	case 0 :
		{
		put('This script will migrate your current Symphony Installation to the DB-Less structure. It will create
				XML-files for all your sections and pages, rendering their references in the database obsolete.');
		put('Please note that this migration script will also edit your datasources and events, and the use of this
				migration is COMPLETELY AT YOUR OWN RISK!', true, false, 'notice');
		put('!!! BACKUP BEFORE YOU BEGIN !!!', true, false, 'error');
		choose('Continue?', array('Yes' => 1));
		break;
		}
	case 1 :
		{
		put('Checking Symphony version...');
		if (version_compare($settings['symphony']['version'], '2.3', '<')) {
			put('Oh oh, it\'s not looking good. Please update your Symphony installation to 2.3 first! (version
					found: ' . $settings['symphony']['version'] . ')');
		} else {
			put('Version is looking a-ok! (version found: ' . $settings['symphony']['version'] . ')');
			choose('Continue?', array('Yes' => 2));
		}
		break;
		}
	case 2 :
		{
		put('Creating lookup tables...', false);
		$db->mq(sprintf('CREATE TABLE IF NOT EXISTS `%slookup_pages` (
					`id` int(11) NOT NULL AUTO_INCREMENT,
					`hash` varchar(32) NOT NULL,
					PRIMARY KEY (`id`)
				) ENGINE=InnoDB  DEFAULT CHARSET=utf8;', $pf));
		$db->mq(sprintf('TRUNCATE TABLE `%slookup_pages`;', $pf));

		$db->mq(sprintf('CREATE TABLE IF NOT EXISTS `%slookup_sections` (
					`id` int(11) NOT NULL AUTO_INCREMENT,
  					`hash` varchar(32) NOT NULL,
  					PRIMARY KEY (`id`)
				) ENGINE=InnoDB  DEFAULT CHARSET=utf8;', $pf));
		$db->mq(sprintf('TRUNCATE TABLE `%slookup_sections`;', $pf));

		$db->mq(sprintf('CREATE TABLE IF NOT EXISTS `%slookup_fields` (
  					`id` int(11) NOT NULL AUTO_INCREMENT,
  					`hash` varchar(32) NOT NULL,
  					PRIMARY KEY (`id`)
				) ENGINE=InnoDB  DEFAULT CHARSET=utf8;', $pf));
		$db->mq(sprintf('TRUNCATE TABLE `%slookup_fields`;', $pf));

		put(' [OK]');

		put('Creating index table...', false);
		$db->mq(sprintf('CREATE TABLE IF NOT EXISTS `%sindex` (
  					`id` int(11) NOT NULL auto_increment,
  					`type` enum(\'pages\',\'sections\') NOT NULL,
  					`md5` varchar(32) NOT NULL,
  					`xml` text NOT NULL,
  					PRIMARY KEY  (`id`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;', $pf));
		$db->mq(sprintf('TRUNCATE TABLE `%sindex`;', $pf));

		put(' [OK]');

		put('Creating sections folder...', false);
		if (!file_exists('workspace/sections')) {
			mkdir('workspace/sections');
		}
		put(' [OK]');

		put('Start crunching sections into XML-files...');
		put('Load sections...');

		$sections = $db->mq(sprintf('SELECT * FROM `%ssections`;', $pf));

		$sectionIDHash = array();
		$sectionFilenames = array();
		$fieldIDHash = array();

		while ($section = mysql_fetch_assoc($sections)) {
			put('Crunching section ' . $section['name'] . ' into a nice fluffy XML-file...', false);
			$xml = new DOMDocument('1.0', 'UTF-8');
			$sectionElement = $xml->createElement('section');
			// Default data:
			$nameNode = $xml->createElement('name', utf8_encode($section['name']));
			$nameNode->setAttribute('handle', $section['handle']);
			$sortorderNode = $xml->createElement('sortorder', $section['sortorder']);
			$hiddenNode = $xml->createElement('hidden', $section['hidden']);
			$navigationgroupNode = $xml->createElement('navigation_group', utf8_encode($section['navigation_group']));
			// create a unique hash:
			$uniquehash = md5($section['name'] . time());
			$uniquehashNode = $xml->createElement('unique_hash', $uniquehash);
			$fieldsNode = $xml->createElement('fields');
			$sectionElement->appendChild($nameNode);
			$sectionElement->appendChild($sortorderNode);
			$sectionElement->appendChild($hiddenNode);
			$sectionElement->appendChild($navigationgroupNode);
			$sectionElement->appendChild($uniquehashNode);

			$associationsNode = $xml->createElement('associations');

			$sectionIDHash[$section['id']] = $uniquehash;
			$sectionFilenames[$section['id']] = 'workspace/sections/' . $section['handle'] . '.xml';

			// Insert in lookup table:
			$db->mq(sprintf('INSERT INTO `%slookup_sections` (`id`, `hash`) VALUES (%d, \'%s\');', $pf, $section['id'], $uniquehash));

			// Iterate through the fields:
			$fields = $db->mq(sprintf('SELECT * FROM `%sfields` WHERE `parent_section` = %d', $pf, $section['id']));
			while ($field = mysql_fetch_assoc($fields)) {
				$fieldNode = $xml->createElement('field');
				$labelNode = $xml->createElement('label', utf8_encode($field['label']));
				$elementnameNode = $xml->createElement('element_name', $field['element_name']);
				$locationNode = $xml->createElement('location', $field['location']);
				$requiredNode = $xml->createElement('required', $field['required']);
				$typeNode = $xml->createElement('type', $field['type']);
				$showcolumnNode = $xml->createElement('show_column', $field['show_column']);
				$sortorderNode = $xml->createElement('sortorder', $field['sortorder']);
				$uniqueHashField = md5($field['label'] . time() . rand(0, 999999999));
				$uniquehashNode = $xml->createElement('unique_hash', $uniqueHashField);

				// Insert in lookup table:
				$db->mq(sprintf('INSERT INTO `%slookup_fields` (`id`, `hash`) VALUES (%d, \'%s\');', $pf, $field['id'], $uniqueHashField));

				$fieldNode->appendChild($labelNode);
				$fieldNode->appendChild($elementnameNode);
				$fieldNode->appendChild($locationNode);
				$fieldNode->appendChild($requiredNode);
				$fieldNode->appendChild($typeNode);
				$fieldNode->appendChild($showcolumnNode);
				$fieldNode->appendChild($sortorderNode);
				$fieldNode->appendChild($uniquehashNode);

				// Add field-specific options:
				$fieldInfo = $db->ma(sprintf('SELECT * FROM `%sfields_%s` WHERE `field_id` = %d',
					$pf, $field['type'], $field['id']));
				foreach ($fieldInfo as $key => $value) {
					if ($key != 'id' && $key != 'field_id') {
						$specificNode = $xml->createElement($key, utf8_encode($value));
						$fieldNode->appendChild($specificNode);
					}
				}
				$fieldsNode->appendChild($fieldNode);

				$fieldIDHash[$field['id']] = $uniqueHashField;

			}

			$sectionElement->appendChild($fieldsNode);
			$sectionElement->appendChild($associationsNode);

			$xml->preserveWhiteSpace = false;
			$xml->formatOutput = true;
			$xml->appendChild($sectionElement);
			// Save XML-file:
			file_put_contents($sectionFilenames[$section['id']], $xml->saveXML());
			put(' [OK]');
		}

		// Associations:
		/*
			<association>
			  <parent_field>eb0d752279d53736e321b255d7f5d07b</parent_field>
			  <child_section>02006a134bfa591ad11949715db4ccfb</child_section>
			  <child_field>0f5ab62785b8fd44d8bb79adaf183e21</child_field>
			  <show_association>yes</show_association>
			</association>
		 */
		// parent_field  = the field in the current section
		// child_section = the related section
		// child_field   = the related field
		// show_association ~ (was hide_association)

		put('Editing the saved XML-files to create them assocations...');
		$associations = $db->mq(sprintf('SELECT * FROM `%ssections_association`;', $pf));
		while ($association = mysql_fetch_assoc($associations)) {
			$file_to_edit = $sectionFilenames[$association['parent_section_id']];
			$parent_field = $fieldIDHash[$association['parent_section_field_id']];
			$child_section = $sectionIDHash[$association['child_section_id']];
			$child_field = $fieldIDHash[$association['child_section_field_id']];
			$show_association = $association['hide_association'] == 'yes' ? 'no' : 'yes';

			$xml = new DOMDocument('1.0', 'UTF-8');
			$xml->preserveWhiteSpace = false;
			$xml->formatOutput = true;
			$xml->load($file_to_edit);

			$association = $xml->createElement('association');
			$association->appendChild($xml->createElement('parent_field', $parent_field));
			$association->appendChild($xml->createElement('child_section', $child_section));
			$association->appendChild($xml->createElement('child_field', $child_field));
			$association->appendChild($xml->createElement('show_association', $show_association));

			$node = $xml->getElementsByTagName('associations')->item(0);
			$node->appendChild($association);

			file_put_contents($file_to_edit, $xml->saveXML());
			put('Association saved...');
		}
		// Pages:
		put('Saving them pages to XML-configuration files...');

		$pages = $db->mq(sprintf('SELECT * FROM `%spages`;', $pf));
		$pageIDHash = array();
		$pageFilenames = array();
		$pageParents = array();
		while ($page = mysql_fetch_assoc($pages)) {
			put('Saving page ' . $page['title'] . ' to it\'s very own XML-file.', false);
			/*
			<page>
			  <title handle="home">Home</title>
			  <unique_hash>b678036842f9749df93b913e74ace0bb</unique_hash>
			  <parent/>
			  <path/>
			  <params/>
			  <datasources>
				<datasource>mijn_datasource</datasource>
			  </datasources>
			  <events/>
			  <types>
				<type>index</type>
			  </types>
			  <sortorder>1</sortorder>
			</page>
							 */
			$xml = new DOMDocument('1.0', 'UTF-8');
			$xml->encoding = 'UTF-8';
			$pageNode = $xml->createElement('page');
			$titleNode = $xml->createElement('title', utf8_encode($page['title']));
			$titleNode->setAttribute('handle', $page['handle']);
			$uniquehash = md5($page['title'] . time() . rand(0, 9999999));
			$uniquehashNode = $xml->createElement('unique_hash', $uniquehash);
			$pageIDHash[$page['id']] = $uniquehash;
			$pathNode = $xml->createElement('path', $page['path']);
			$paramsNode = $xml->createElement('params', $page['params']);

			$datasourcesNode = $xml->createElement('datasources');
			$dss = explode(',', $page['data_sources']);
			foreach ($dss as $ds) {
				$datasourcesNode->appendChild($xml->createElement('datasource', $ds));
			}

			$eventsNode = $xml->createElement('events');
			$events = explode(',', $page['events']);
			foreach ($events as $event) {
				$eventsNode->appendChild($xml->createElement('event', $event));
			}

			$typesNode = $xml->createElement('types');
			$types = $db->mq(sprintf('SELECT * FROM `%spages_types` WHERE `page_id` = %d;', $pf, $page['id']));
			while ($type = mysql_fetch_assoc($types)) {
				$typesNode->appendChild($xml->createElement('type', $type['type']));
			}

			$sortorderNode = $xml->createElement('sortorder', $page['sortorder']);

			$pageNode->appendChild($titleNode);
			$pageNode->appendChild($uniquehashNode);
			$pageNode->appendChild($pathNode);
			$pageNode->appendChild($paramsNode);
			$pageNode->appendChild($datasourcesNode);
			$pageNode->appendChild($eventsNode);
			$pageNode->appendChild($typesNode);
			$pageNode->appendChild($sortorderNode);
			$xml->appendChild($pageNode);

			if (!is_null($page['parent'])) {
				$pageParents[$page['id']] = $page['parent'];
				$filename = 'workspace/pages/' . str_replace('/', '_', $page['path']) . '_' . $page['handle'] . '.xml';
			} else {
				$filename = 'workspace/pages/' . $page['handle'] . '.xml';
			}

			// Insert in lookup table:
			$db->mq(sprintf('INSERT INTO `%slookup_pages` (`id`, `hash`) VALUES (%d, \'%s\');', $pf, $page['id'], $uniquehash));

			$pageFilenames[$page['id']] = $filename;

			$xml->preserveWhiteSpace = false;
			$xml->formatOutput = true;
			file_put_contents($filename, $xml->saveXML());

			put(' [OK]');
		}

		put('Saving page parents...');
		foreach ($pageParents as $pageID => $parentID) {
			$file_to_edit = $pageFilenames[$pageID];

			put('Opening ' . $file_to_edit . '...', false);

			$xml = new DOMDocument('1.0', 'UTF-8');
			$xml->preserveWhiteSpace = false;
			$xml->formatOutput = true;
			$xml->load($file_to_edit);

			$node = $xml->getElementsByTagName('page')->item(0);
			$node->appendChild($xml->createElement('parent', $pageIDHash[$parentID]));

			file_put_contents($file_to_edit, $xml->saveXML());

			put('[Parent saved]');
		}

		function replaceSectionIDWithHash($matches)
		{
			global $sectionIDHash;
			if(is_numeric($matches[1]))
			{
				return str_replace($matches[1], 'section:'.$sectionIDHash[$matches[1]], $matches[0]);
			} else {
				return $matches[0];
			}
		}

		function replaceSectionFilters($matches)
		{
			global $fieldIDHash;
			$items = explode(',', $matches[1]);
			$itemsArr = array();
			foreach($items as $item)
			{
				$item = trim($item);
				if(!empty($item))
				{
					$a = explode('=>', $item);
					$nr = trim(str_replace('\'', '', $a[0]));
					// Only change numeric:
					if(is_numeric($nr))
					{
						$item = '\''.$fieldIDHash[$nr].'\' => '.$a[1];
					}
					$itemsArr[] = $item;
				}
			}
			return str_replace($matches[1], implode(",\n", $itemsArr), $matches[0]);
		}

		put('Editing datasources...');
		$files = glob('workspace/data-sources/data.*.php');
		foreach($files as $file)
		{
			put('Editing '.$file, false);
			$str = file_get_contents($file);
			$str = preg_replace_callback('/public function getSource\(\)\{\s+return \'(.*)\';(.*)\s}/msU',
				'replaceSectionIDWithHash', $str, -1, $count);

			// Edit the filters:
			$str = preg_replace_callback('/public \$dsParamFILTERS = array\(\s+(.*)\s+\);/msU',
				'replaceSectionFilters', $str);

			file_put_contents($file, $str);
			if($count == 1)
			{
				put(' [OK]');
			} else {
				put(' [ERROR: Could not change getSource()-function. Please change it manually so the return value is
					\'section:unique_hash_of_the_section\'.', true, false, 'error');
			}
			preg_match('/public function allowEditorToParse\(\)\{\s+return true;(.*)\s}/msU', $str, $matches);
			if(count($matches[0]) == 0) {
				put('[NOTICE: This datasource could be manually customized. Please make sure to re-check your code to
					make sure everything will work OK]', true, false, 'notice');
			}
		}

		put('Editing events...');
		$files = glob('workspace/events/event.*.php');
		foreach($files as $file)
		{
			put('Editing '.$file, false);
			$str = file_get_contents($file);
			$str = preg_replace_callback('/public function getSource\(\)\{\s+return \'(\d+)\';(.*)\s}/msU',
				'replaceSectionIDWithHash', $str, -1, $count);

			file_put_contents($file, $str);

			if($count == 1)
			{
				put(' [OK]');
			} else {
				put(' [ERROR: Could not change getSource()-function. Please change it manually so the return value is
					\'section:unique_hash_of_the_section\'.', true, false, 'error');
			}
			preg_match('/public function allowEditorToParse\(\)\{\s+return true;(.*)\s}/msU', $str, $matches);
			if(count($matches[0]) == 0) {
				put('[NOTICE: This event could be manually customized. Please make sure to re-check your code to
					make sure everything will work OK]', true, false, 'notice');
			}
		}

		put('Everything is done!', true, true);
		put('You can now overwrite your Symphony Installation with the files of the DB-Less Symphony fork. Please note
			that you might have to edit a lot of fields used by your extensions and stuff...');
		put('If you want, this migration script can automaticly drop all the no longer needed tables for you from the
			database. ', false);
		put('Please note that this is at your own risk!', true, false, 'notice');
		choose('Do you want to drop the no longer needed database tables?', array('Yes'=>3, 'No'=>4));
		break;
		}
	case 3:
		{
			put('Dropping them tables...');
			// TODO
			choose('Complete', array('Finish'=>4));
			break;
		}
	case 4:
		{
			put('Migration complete');
			file_put_contents('migration_complete', '');
			break;
		}

}




?>
</body>
</html>