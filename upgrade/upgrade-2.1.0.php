<?php
if (!defined('_PS_VERSION_')) {
	exit;
}

/**
 * This function updates your module from previous versions to the version 1.1,
 * usefull when you modify your database, or register a new hook ...
 * Don't forget to create one file per version.
 */
function upgrade_module_2_1_0($module)
{
	Configuration::deleteByName( 'VATCHECKER_REQUIRED' );

	return true;
}
