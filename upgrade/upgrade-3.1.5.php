<?php
if ( ! defined( '_PS_VERSION_' ) ) {
	exit;
}

/**
 * This function updates your module from previous versions to the version 3.1.5,
 *
 * @param  Vatchecker  $module
 * @return bool
 * /
 */
function upgrade_module_3_1_5( $module )
{
	$success = true;

	if (method_exists($module, 'uninstallOverrides')) {
		$success &= $module->uninstallOverrides();
	}

	if (method_exists($module, 'installOverrides')) {
		$success &= $module->installOverrides();
	}

	$success &= Configuration::updateValue('VATCHECKER_ADDRESS_SELECT', 'shipping_only');

	if (class_exists('Cache')) {
		Cache::clean('*');
	}

	if (class_exists('Tools')) {
		Tools::clearAllCache();
	}

	return (bool) $success;
}
