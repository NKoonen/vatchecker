<?php
require_once('../../config/config.inc.php');
require_once('../../init.php');
require_once(dirname(__FILE__).'/vatchecker.php');

if ( ! Tools::isSubmit( 'vatchecker' ) ) {
	die;
}

$vatchecker = new Vatchecker();

$vat     = Tools::getValue('vat_number');
$country = Tools::getValue('id_country');

$is_valid = $vatchecker->checkVat( $vat, $country );

$return = array(
	'valid' => ( true === $is_valid ),
	'error' => ( true !== $is_valid ) ? $is_valid : '',
);

echo json_encode( $return );
die;
