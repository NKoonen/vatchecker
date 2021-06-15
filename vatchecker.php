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
		//'GB', // Brexit!
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
		$this->version = '1.2.1';
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
		Configuration::updateValue('VATCHECKER_REQUIRED', true);
		Configuration::updateValue('VATCHECKER_ALLOW_OFFLINE', true);
		//Configuration::updateValue('VATCHECKER_EU_COUNTRIES', null );
		//Configuration::updateValue('VATCHECKER_ORIGIN_COUNTRY', null);
		//Configuration::updateValue('VATCHECKER_NO_TAX_GROUP', null);

		return parent::install() &&
			$this->registerHook('displayHeader') &&
			$this->registerHook('displayBeforeBodyClosingTag') &&
			$this->registerHook('actionValidateCustomerAddressForm') &&
			$this->registerHook('actionCartSave');
	}

	public function uninstall()
	{
		Configuration::deleteByName('VATCHECKER_LIVE_MODE');
		Configuration::deleteByName('VATCHECKER_REQUIRED');
		Configuration::deleteByName('VATCHECKER_ALLOW_OFFLINE');
		Configuration::deleteByName('VATCHECKER_ORIGIN_COUNTRY');
		Configuration::deleteByName('VATCHECKER_EU_COUNTRIES');
		Configuration::deleteByName('VATCHECKER_NO_TAX_GROUP');

		return parent::uninstall();
	}

	/**
	 * @since 1.1.0
	 * @param array $params
	 */
	public function hookDisplayHeader( $params )
	{
		$this->context->controller->addJS( $this->_path . 'views/js/front.js' );
	}

	/**
	 * @since 1.1.0
	 * @param array $params
	 */
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
			if ( false !== strpos( $key, 'VATCHECKER_EU_COUNTRIES' ) ) {
				continue;
			}
			Configuration::updateValue($key, Tools::getValue($key));
		}

		$eu_countries = array();
		$countries    = Country::getCountries($this->context->language->id);
		foreach ( $countries as $country ) {
			$id = $country['id_country'];
			if ( Tools::getValue( 'VATCHECKER_EU_COUNTRIES_' . $id ) ) {
				$eu_countries[ $id ] = Country::getIsoById( $id );
			}
		}

		Configuration::updateValue( 'VATCHECKER_EU_COUNTRIES', json_encode( $eu_countries ) );
	}

	/**
	 * Set values for the inputs.
	 */
	protected function getConfigFormValues()
	{
		$values = array(
			'VATCHECKER_LIVE_MODE'      => Configuration::get('VATCHECKER_LIVE_MODE', true),
			'VATCHECKER_REQUIRED'       => Configuration::get('VATCHECKER_REQUIRED', true),
			'VATCHECKER_ALLOW_OFFLINE'  => Configuration::get('VATCHECKER_ALLOW_OFFLINE', true),
			'VATCHECKER_ORIGIN_COUNTRY' => Configuration::get('VATCHECKER_ORIGIN_COUNTRY', '0'),
			'VATCHECKER_NO_TAX_GROUP'   => Configuration::get('VATCHECKER_NO_TAX_GROUP', null),
		);

		$countries = $this->getEUCountries();
		foreach ( $countries as $id => $iso ) {
			$values['VATCHECKER_EU_COUNTRIES_' . $id] = true;
		}

		return $values;
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
			'languages'    => $this->context->controller->getLanguages(),
			'id_language'  => $this->context->language->id,
		);

		return $helper->generateForm(array($this->getConfigForm()));
	}

	/**
	 * Create the structure of your form.
	 */
	protected function getConfigForm()
	{
		$countries = Country::getCountries($this->context->language->id);
		$select_country = array(
			0 => array(
				'id'   => 0,
				'name' => $this->l('- Select a country -'),
			),
		);
		foreach ($countries as $country) {
			$cntylist[] = array(
				'id'    => $country['id_country'],
				'name'  => $country['name'] . ' (' . $country['iso_code'] . ')',
			);
		}

		return array(
			'form' => array(
				'legend' => array(
					'title' => $this->l('Settings'),
					'icon'  => 'icon-cogs',
				),
				'input' => array(
					array(
						'type'    => 'switch',
						'label'   => $this->l('Activate module'),
						'name'    => 'VATCHECKER_LIVE_MODE',
						'is_bool' => true,
						'desc'    => $this->l('Use this module currently active'),
						'values'  => array(
							array(
								'id'    => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled'),
							),
							array(
								'id'    => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled'),
							),
						),
					),
					array(
						'type'    => 'switch',
						'label'   => $this->l('Validation required'),
						'name'    => 'VATCHECKER_REQUIRED',
						'is_bool' => true,
						'desc'    => $this->l('Require valid VAT numbers for EU countries (VIES).'),
						'values'  => array(
							array(
								'id'    => 'required_enabled',
								'value' => true,
								'label' => $this->l('Enabled'),
							),
							array(
								'id'    => 'required_disabled',
								'value' => false,
								'label' => $this->l('Disabled'),
							),
						),
					),
					array(
						'type'    => 'switch',
						'label'   => $this->l('Offline validation'),
						'name'    => 'VATCHECKER_ALLOW_OFFLINE',
						'is_bool' => true,
						'desc'    => $this->l('Accept VAT numbers if the VIES validation service is offline'),
						'values'  => array(
							array(
								'id'    => 'offline_enabled',
								'value' => true,
								'label' => $this->l('Enabled'),
							),
							array(
								'id'    => 'offline_disabled',
								'value' => false,
								'label' => $this->l('Disabled'),
							),
						),
					),
					array(
						'col'     => 3,
						'type'    => 'select',
						'desc'    => $this->l('Select shops country'),
						'name'    => 'VATCHECKER_ORIGIN_COUNTRY',
						'label'   => $this->l('Origin'),
						'options' => array(
							'query' => array_merge( $select_country, $cntylist ),
							'id'    => 'id',
							'name'  => 'name',
						),
					),
					array(
						'col'      => 3,
						'type'     => 'checkbox',
						'desc'     => $this->l('Select EU countries'),
						'name'     => 'VATCHECKER_EU_COUNTRIES',
						'label'    => $this->l('EU Countries'),
						'multiple' => true,
						'values'   => array(
							'query' => $cntylist,
							'id'    => 'id',
							'name'  => 'name',
							'value' => 'id',
						),
					),
					array(
						'type'    => 'select',
						'name'    => 'VATCHECKER_NO_TAX_GROUP',
						'label'   => $this->l('Valid VAT Group'),
						'desc'    => $this->l('Customers with valid VAT number will be place in this group.'),
						'options' => array(
							'query'   => Group::getGroups(Context::getContext()->language->id, true),
							'id'      => 'id_group',
							'name'    =>'name',
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

	/**
	 * @since 1.2
	 */
	public function hookActionCartSave() {
		if ( ! $this->context->cart ) {
			return;
		}

		$address_id = $this->context->cart->getTaxAddressId();
		$address = new Address( $address_id );

		$countryId = $address->id_country;
		$vatNumber = $address->vat_number;

		$vatValid = $this->checkVat( $vatNumber, $countryId );

		if ( null === $vatValid ) {
			// Module inactive.
			return;
		}

		$this->updateNoTaxGroup( $vatValid, $countryId, $this->context->customer );
	}

	/**
	 * @since 1.0
	 * @param $params
	 * @return bool
	 */
	public function hookActionValidateCustomerAddressForm(&$params)
	{
		if ( empty( $params['form'] ) ) {
			return true;
		}

		$form       = $params['form'];
		$countryId  = $form->getField('id_country')->getValue();
		if ( ! $form->getField('vat_number') ) {
			return true;
		}
		$vatNumber = $form->getField('vat_number')->getValue();

		$vatValid = $this->checkVat( $vatNumber, $countryId );

		if ( null === $vatValid ) {
			// Module inactive.
			return true;
		}

		$this->updateNoTaxGroup( $vatValid, $countryId, $this->context->customer );

		if ( true !== $vatValid && Configuration::get( 'VATCHECKER_REQUIRED' ) ) {
			$form->getField('vat_number')->addError( $vatValid );
			return false;
		}

		return true;
	}

	/**
	 * @since 1.1.0
	 * @param string     $vatNumber
	 * @param int|string $countryCode
	 * @return bool|null
	 */
	public function checkVat( $vatNumber, $countryCode = null ) {

		if ( ! Configuration::get( 'VATCHECKER_LIVE_MODE' ) ) {
			return null;
		}

		if ( is_numeric( $countryCode ) ) {
			$countryCode = Country::getIsoById( $countryCode );
		}

		if ( ! $this->isEUCountry( $countryCode ) ) {
			return $this->l('Please select an EU country');
		}

		$vatNumber = str_replace( $countryCode, "", $vatNumber);
		return $this->checkVies( $countryCode, $vatNumber );
	}

	/**
	 * @since 1.0
	 * @param string $countryCode
	 * @param string $vatNumber
	 * @return bool|string
	 */
	protected function checkVies( $countryCode, $vatNumber )
	{
		try {

			$client = new SoapClient($this->_SOAPUrl);

			$params = array(
				'countryCode' => $countryCode,
				'vatNumber' => $vatNumber,
			);

			$result = $client->__soapCall('checkVat', array($params));

			if ( $result->valid === true ) {
				return true;
			}
			return $this->l('This is not a valid VAT number');

		} catch ( Throwable $e ) {
			if ( Configuration::get( 'VATCHECKER_ALLOW_OFFLINE' ) ) {
				return true;
			}
			return $this->l( 'EU VIES server not responding' );
		}
	}

	/**
	 * @since 1.1.0
	 * @return array
	 */
	public function getEUCountries() {
		$countries = json_decode( Configuration::get( 'VATCHECKER_EU_COUNTRIES' ), true );
		if ( ! $countries ) {
			$all_countries = Country::getCountries( $this->context->language->id );
			$countries     = array();
			foreach ( $all_countries as $country ) {
				if ( in_array( $country['iso_code'], $this->EUCountries, true ) ) {
					$countries[ $country['id_country'] ] = $country['iso_code'];
				}
			}
		}
		return $countries;
	}

	/**
	 * @since 1.1.0
	 * @param int|string $countryCode
	 * @return bool
	 */
	public function isEUCountry( $countryCode ) {

		if ( is_numeric( $countryCode ) ) {
			$countryCode = Country::getIsoById( $countryCode );
		}

		return in_array( $countryCode, $this->getEUCountries() );
	}

	/**
	 * @since 1.2.1
	 * @return int|null
	 */
	public function getNoTaxGroup() {
		return Configuration::get('VATCHECKER_NO_TAX_GROUP');
	}

	/**
	 * @since 1.1.1
	 * @param bool|string $vatValid
	 * @param int         $countryId
	 * @param Customer    $customer
	 */
	public function updateNoTaxGroup( $vatValid, $countryId, $customer = null ) {
		if ( ! $customer ) {
			$customer = $this->context->customer;
		}

		if ( is_string( $vatValid ) ) {
			$vatValid = $this->checkVat( $vatValid, $countryId );

			if ( null === $vatValid ) {
				// Module inactive.
				return;
			}
		}

		$is_origin_country = ( (int) Configuration::get( 'VATCHECKER_ORIGIN_COUNTRY' ) === (int) $countryId );

		if ( true === $vatValid ) {

			if ( ! $is_origin_country ) {
				// If all is correct, put the customer in the no TAX group.
				$this->addNoTaxGroup( $customer );
			} else {
				$this->removeNoTaxGroup( $customer );
			}
		} else {
			$this->removeNoTaxGroup( $customer );
		}
	}

	/**
	 * @since 1.1.0
	 * @param Customer $customer
	 */
	public function addNoTaxGroup( $customer ) {
		$group = $this->getNoTaxGroup();
		if ( ! $group ) {
			return;
		}

		$customer->addGroups( array( (int) $group ) );
	}

	/**
	 * @since 1.1.0
	 * @param Customer $customer
	 */
	public function removeNoTaxGroup( $customer ) {
		$group = $this->getNoTaxGroup();
		if ( ! $group ) {
			return;
		}

		// Remove from group.
		$groups = $customer->getGroups();
		$groups = array_diff( $groups, array( (int) $group ) );
		if ( empty( $groups ) ) {
			$groups = array( Configuration::get( 'PS_CUSTOMER_GROUP' ) );
		}
		$customer->updateGroup( $groups );
	}

	/**
	 * @since 1.2.1
	 * @param int|Customer $customer
	 * @return bool
	 */
	public function hasNoTaxGroup( $customer ) {
		$group = $this->getNoTaxGroup();
		if ( ! $group ) {
			return false;
		}

		if ( $customer instanceof Customer ) {
			return in_array( $group, $customer->getGroups() );
		}
		return in_array( $group, Customer::getGroupsStatic( $customer ) );
	}
}
