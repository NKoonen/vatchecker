<?php
if ( ! defined( '_PS_VERSION_' ) ) {
	exit;
}

/**
 * This function updates your module from previous versions to the version 3.1.1,
 */
function upgrade_module_3_1_1( $module )
{
	$module->uninstallOverrides();
	$module->installOverrides();
	Configuration::updateValue('VATCHECKER_CARRIER_NOTAX', true );
}
