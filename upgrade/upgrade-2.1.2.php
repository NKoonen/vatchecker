<?php
if ( ! defined( '_PS_VERSION_' ) ) {
	exit;
}

/**
 * This function updates your module from previous versions to the version 2.1.2,
 * usefull when you modify your database, or register a new hook ...
 * Don't forget to create one file per version.
 */
function upgrade_module_2_1_2( $module )
{
	$module->uninstallOverrides();
	$module->installOverrides();
	Configuration::updateValue('VATCHECKER_TAXRATE_RULE', null );
	$module->registerHook('displayAdminProductsMainStepLeftColumnMiddle');
	$module->registerHook('actionProductUpdate');

	return Db::getInstance()->Execute(
		'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'vatchecker_excluded_products' . '` (
			`id_product` INTEGER UNSIGNED NOT NULL,
			PRIMARY KEY(`id_product`)
		) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8'
	);
}
