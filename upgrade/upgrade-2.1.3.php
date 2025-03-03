<?php
if ( ! defined( '_PS_VERSION_' ) ) {
	exit;
}

/**
 * This function updates your module from previous versions to the version 2.1.3,
 * usefull when you modify your database, or register a new hook ...
 * Don't forget to create one file per version.
 */
function upgrade_module_2_1_3( $module )
{
	return Db::getInstance()->Execute(
		'ALTER TABLE `' . _DB_PREFIX_ . 'vatchecker` ADD INDEX(`vat_number`);'
	);
}
