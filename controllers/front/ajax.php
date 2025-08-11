<?php
/**
 * @since 3.1.4
 */
class VatcheckerAjaxModuleFrontController extends ModuleFrontController
{
	public $ajax = true;

	public function initContent()
	{
		parent::initContent();

		if (Tools::getValue('vatchecker') !== Tools::getToken('vatchecker')) {
			die(json_encode(['error' => 'Invalid token']));
		}

		require_once _PS_MODULE_DIR_ . 'vatchecker/vatchecker.php';

		$vatchecker = new Vatchecker();

		$vatNumber = Tools::getValue('vat_number');
		$countryId = Tools::getValue('id_country');
		$company   = Tools::getValue('company');

		$is_eu    = $vatchecker->isEUCountry($countryId);
		$checkVat = $vatchecker->checkVat($vatNumber, $countryId, $company);
		$vatValid = $checkVat['valid'];
		$vatError = $checkVat['error'];

		$valid = ($vatValid === true);

		if (!$is_eu) {
			$valid = null;
		}

		$return = [
			'valid' => $valid,
			'error' => $vatError,
			'is_eu' => $is_eu,
		];

		header('Content-Type: application/json');
		echo json_encode($return);
		exit;
	}
}
