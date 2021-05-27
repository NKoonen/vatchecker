<?php
/**
 * 2007-2020 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2020 PrestaShop SA
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

if (!defined('_PS_VERSION_')) {
	exit;
}

class Vatchecker extends Module
{
	protected $config_form = false;
	private $_SOAPUrl = 'https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl';
	private $EUCountries = array(
		'AT',
		'BE',
		'BG',
		'CY',
		'CZ',
		'DE',
		'DK',
		'EE',
		'GR',
		'ES',
		'FI',
		'FR',
		'GB',
		'HR',
		'HU',
		'IE',
		'IT',
		'LT',
		'LU',
		'LV',
		'MT',
		'NL',
		'PL',
		'PT',
		'RO',
		'SE',
		'SI',
		'SK',
	);

	public function __construct()
	{
		$this->name = 'vatchecker';
		$this->tab = 'billing_invoicing';
		$this->version = '1.1.0';
		$this->author = 'Inform-All';
		$this->need_instance = 1;

		/**
		 * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
		 */
		$this->bootstrap = true;

		parent::__construct();

		$this->displayName = $this->l('Vat Checker');
		$this->description = $this->l(
			'Check if a customers VAT number is valid and gives the customer 0 tax if the customer is not in from country.'
		);

		$this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
	}

	/**
	 * Don't forget to create update methods if needed:
	 * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
	 */
	public function install()
	{
		Configuration::updateValue('VATCHECKER_LIVE_MODE', true);

		return parent::install() &&
			$this->registerHook('displayHeader') &&
			$this->registerHook('displayBeforeBodyClosingTag') &&
			$this->registerHook('actionValidateCustomerAddressForm');
	}

	public function uninstall()
	{
		Configuration::deleteByName('VATCHECKER_LIVE_MODE');

		return parent::uninstall();
	}

	public function hookDisplayHeader( $params )
	{
		$this->context->controller->addJS( $this->_path . 'views/js/front.js' );
	}

	public function hookDisplayBeforeBodyClosingTag( $params )
	{
		$json = array(
			'ajax_url' => $this->getPathUri() . 'ajax.php',
			'token' => Tools::getToken( 'vatchecker' ),
		);

		echo '<script id="vatchecker_js">var vatchecker = ' . json_encode( $json ) . '</script>';
	}

	/**
	 * Load the configuration form
	 */
	public function getContent()
	{
		/**
		 * If values have been submitted in the form, process.
		 */
		if (((bool)Tools::isSubmit('submitVatcheckerModule')) == true) {
			$this->postProcess();
		}

		$this->context->smarty->assign('module_dir', $this->_path);

		$output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');
		$output = $output.$this->renderForm();

		return $output;
	}

	/**
	 * Save form data.
	 */
	protected function postProcess()
	{
		$form_values = $this->getConfigFormValues();

		foreach (array_keys($form_values) as $key) {
			Configuration::updateValue($key, Tools::getValue($key));
		}
	}

	/**
	 * Set values for the inputs.
	 */
	protected function getConfigFormValues()
	{
		return array(
			'VATCHECKER_LIVE_MODE' => Configuration::get('VATCHECKER_LIVE_MODE', true),
			'VATCHECKER_ORIGIN_COUNTRY' => Configuration::get('VATCHECKER_ORIGIN_COUNTRY', '0'),
			'VATCHECKER_NO_TAX_GROUP' => Configuration::get('VATCHECKER_NO_TAX_GROUP', null),
		);
	}

	/**
	 * Create the form that will be displayed in the configuration of your module.
	 */
	protected function renderForm()
	{
		$helper = new HelperForm();

		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$helper->module = $this;
		$helper->default_form_language = $this->context->language->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

		$helper->identifier = $this->identifier;
		$helper->submit_action = 'submitVatcheckerModule';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
			.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');

		$helper->tpl_vars = array(
			'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id,
		);

		return $helper->generateForm(array($this->getConfigForm()));
	}

	/**
	 * Create the structure of your form.
	 */
	protected function getConfigForm()
	{
		$countries = Country::getCountries($this->context->language->id);
		$cntylist = array(
			0 => array(
				'id' => 0,
				'name' => $this->l('- Select a country -'),
			),
		);
		foreach ($countries as $country) {
			$cntylist[] = array(
				'id' => $country['id_country'],
				'name' => $country['name'],
			);
		}

		return array(
			'form' => array(
				'legend' => array(
					'title' => $this->l('Settings'),
					'icon' => 'icon-cogs',
				),
				'input' => array(
					array(
						'type' => 'switch',
						'label' => $this->l('Activate module'),
						'name' => 'VATCHECKER_LIVE_MODE',
						'is_bool' => true,
						'desc' => $this->l('Use this module currently active'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled'),
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled'),
							),
						),
					),
					array(
						'col' => 3,
						'type' => 'select',
						'desc' => $this->l('Select shops country'),
						'name' => 'VATCHECKER_ORIGIN_COUNTRY',
						'label' => $this->l('Origin'),
						'options' => array(
							'query' => $cntylist,
							'id' => 'id',
							'name' => 'name',
						),
					),
					array(
						'type' => 'select',
						'name' => 'VATCHECKER_NO_TAX_GROUP',
						'label' => $this->l('Valid VAT Group'),
						'desc' => $this->l('Customers with valid VAT number will be place in this group.'),
						'options' => array(
							'query' => Group::getGroups(Context::getContext()->language->id, true),
							'id' => 'id_group',
							'name'=>'name',
							'default' => array(
								'value' => '',
								'label' => $this->l('Select Group')
							)
						)
					),
				),
				'submit' => array(
					'title' => $this->l('Save'),
				),
			),
		);
	}

	public function hookActionValidateCustomerAddressForm(&$params)
	{
		$form       = $params['form'];
		$id_country = $form->getField('id_country')->getValue();
		$vatNumber  = $form->getField('vat_number')->getValue();

		$is_valid = $this->checkVat( $vatNumber, $id_country );

		if ( true === $is_valid ) {

			$is_origin_country = ( Configuration::get('VATCHECKER_ORIGIN_COUNTRY') === $id_country );
			$group             = Configuration::get('VATCHECKER_NO_TAX_GROUP');

			if ( ! $is_origin_country && $group ) {
				// If all is correct, put the customer in the group.
				$this->context->customer->addGroups( array( (int) $group ) );
			}
		} else {
			// @todo Remove from group.
			if ( null === $is_valid ) {
				// VAT number
				$is_valid = true;
			} else {
				$form->getField('vat_number')->addError( $is_valid );
			}
		}

		return $is_valid;

	}

	public function checkVat( $vatNumber, $countryCode = null ) {

		if ( ! Configuration::get('VATCHECKER_LIVE_MODE', true ) ) {
			return null;
		}

		if ( is_numeric( $countryCode ) ) {
			$countryCode = Country::getIsoById( $countryCode );
		}

		if ( ! in_array( $countryCode, $this->EUCountries ) ) {
			return $this->l('Please select an EU country');
		}

		$vatNumber = str_replace( $countryCode, "", $vatNumber);
		return $this->checkVies( $countryCode, $vatNumber );
	}

	protected function checkVies( $countryCode, $vatNumber )
	{
		try {

			$client = new SoapClient($this->_SOAPUrl);

			$params = array(
				'countryCode' => $countryCode,
				'vatNumber' => $vatNumber,
			);

			$result = $client->__soapCall('checkVat', array($params));

			if ($result->valid === true) {
				return true;
			} else {
				return $this->l('This is not a valid VAT number');
			}
		} catch ( Throwable $e ) {
			return $this->l( 'EU VIES server not responding' );
		}
	}
}
