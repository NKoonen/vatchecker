<?php
/**
 * @since 1.1.0
 */
require_once('../../config/config.inc.php');
require_once('../../init.php');
require_once(dirname(__FILE__).'/vatchecker.php');

if ( Tools::getValue('vatchecker') !== Tools::getToken( 'vatchecker' ) ) {
	die;
}

$vatchecker = new Vatchecker();

$vat     = Tools::getValue('vat_number');
$country = Tools::getValue('id_country');

$is_valid = $vatchecker->checkVat( $vat, $country );
$is_eu    = $vatchecker->isEUCountry( $country );

$error = ( true !== $is_valid ) ? $is_valid : '';
$valid = ( true === $is_valid );

if ( ! $vatchecker->isEUCountry( $country ) ) {
	$valid = null;
}

$return = array(
	'valid' => $valid,
	'error' => $error,
	'is_eu' => $is_eu,
);

echo json_encode( $return );
die;
