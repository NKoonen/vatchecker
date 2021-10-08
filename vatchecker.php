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
		$this->version = '1.3.0';
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
			$this->installDB() &&
			$this->registerHook('displayHeader') &&
			$this->registerHook('displayBeforeBodyClosingTag') &&
			$this->registerHook('actionValidateCustomerAddressForm') &&
			$this->registerHook('actionCartSave');
	}

	/**
	 * @since 1.3.0
	 * @return bool
	 */
	public function installDB()
	{
		$sql = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'vatchecker'.'` (
			`id_vatchecker` int(12) UNSIGNED NOT NULL AUTO_INCREMENT,
			`id_address` INTEGER UNSIGNED NOT NULL,
			`id_country` INTEGER UNSIGNED NOT NULL,
			`company` varchar(255) default \'\',
			`vat_number` varchar(32) NOT NULL,
			`valid` int(1) UNSIGNED NOT NULL,
			`date_add` datetime NOT NULL,
			`date_modified` datetime NOT NULL,
			`date_valid_vat` datetime,
			PRIMARY KEY(`id_vatchecker`)
		) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8';

		return Db::getInstance()->execute($sql);
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
			'VATCHECKER_LIVE_MODE'      => Configuration::get( 'VATCHECKER_LIVE_MODE', null, null, null, true ),
			'VATCHECKER_REQUIRED'       => Configuration::get( 'VATCHECKER_REQUIRED', null, null, null, true ),
			'VATCHECKER_ALLOW_OFFLINE'  => Configuration::get( 'VATCHECKER_ALLOW_OFFLINE', null, null, null, true ),
			'VATCHECKER_ORIGIN_COUNTRY' => Configuration::get( 'VATCHECKER_ORIGIN_COUNTRY', null, null, null, '0' ),
			'VATCHECKER_NO_TAX_GROUP'   => Configuration::get( 'VATCHECKER_NO_TAX_GROUP', null, null, null, null ),
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
						'label'   => $this->l('Valid VAT Group') . ' (' . $this->l( 'Optional' ) . ')',
						'desc'    => $this->l('Customers with valid VAT number will be placed in this group.'),
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
		static $cache = array();
		if ( ! $this->context->cart ) {
			return;
		}

		// Only run method on checkout page.
		$controller = $this->context->controller->php_self;
		if ( ! in_array( $controller, array( 'order', 'checkoutpayment-form' ) ) ) {
			return;
		}

		$address_id = $this->context->cart->getTaxAddressId();
		if ( ! $address_id ) {
			return;
		}

		$address   = new Address( $address_id );
		$cache_key = $address_id . $address->id_country . $address->vat_number;

		if ( isset( $cache[ $cache_key ] ) ) {
			$vatValid = true === $cache[ $cache_key ];
			$this->updateNoTaxGroup( $vatValid, $address->id_country, $this->context->customer );

			return;
		}

		$vatValid = false;
		if ( ! $this->isOriginCountry( $address->id_country ) ) {
			$vatValid = $this->isValidVat( $address, true );
		}

		if ( null === $vatValid ) {
			// Module inactive or VIES server offline.
			return;
		}

		$vatValid = true === $vatValid;

		$this->updateNoTaxGroup( $vatValid, $address->id_country, $this->context->customer );

		$cache[ $cache_key ] = $vatValid;
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

		/** @var CustomerAddressFormCore $form */
		$form       = $params['form'];
		$countryId  = $form->getField('id_country')->getValue();
		if ( ! $form->getField('vat_number') ) {
			return true;
		}
		$vatNumber = $form->getField('vat_number')->getValue();

		$vatValid = $this->checkVat( $vatNumber, $countryId, true );

		if ( null === $vatValid ) {
			// Module inactive or VIES server offline.
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
	 * Check if an address can order without VAT.
	 *
	 * @since 1.3.0
	 *
	 * @param Address $address
	 *
	 * @return bool
	 */
	public function canOrderWithoutVat( $address ) {
		$address = $this->getAddress( $address );
		if ( ! $address ) {
			return false;
		}

		if ( $this->isOriginCountry( $address->id_country ) ) {
			return false;
		}

		return $this->isValidVat( $address, false );
	}

	/**
	 * Check if a VAT number is valid using the address data.
	 *
	 * @since 1.3.0
	 *
	 * @param Address $address
	 * @param bool $error Return error (if any) instead of boolean.
	 *
	 * @return bool|string Optionally returns string on error if errors are enabled.
	 */
	public function isValidVat( $address, $error = false ) {
		$address = $this->getAddress( $address );
		if ( ! $address ) {
			return false;
		}

		/**
		 * @var array $result {
		 *     @type int    id_vatchecker
		 *     @type int    id_address
		 *     @type int    id_country
		 *     @type string company
		 *     @type string vat_number
		 *     @type bool   valid
		 *     @type string date_add
		 *     @type string date_modified
		 *     @type string date_valid_vat
		 * }
		 */
		$result = $this->getVatValidation( $address );

		if ( $result ) {

			// VIES API already ran successfully within 24 hours.
			if ( strtotime( $result['date_modified'] ) > strtotime( '-1 day' ) ) {
				return (bool) $result['valid'];
			}

		} else {
			$result = array(
				'id_address'     => $address->id,
				'id_country'     => $address->id_country,
				'company'        => $address->company,
				'vat_number'     => $address->vat_number,
				'valid'          => false,
				'date_add'       => '',
				'date_modified'  => '',
				'date_valid_vat' => '',
			);
		}

		$vatCheck = $this->checkVat( $address->vat_number, $address->id_country, true );

		// Make sure it's a boolean, otherwise it's an error so we don't want to update the database.
		if ( is_bool( $vatCheck ) ) {
			$result['valid'] = $vatCheck;
			$this->setVatValidation( $result );

			return $vatCheck;
		}

		if ( ! $error ) {
			// Convert null to true: module offline.
			return null === $vatCheck;
		}
		return $vatCheck;
	}

	/**
	 * Get VAT validation from the database.
	 *
	 * @since 1.3.0
	 *
	 * @throws PrestaShopDatabaseException
	 *
	 * @param Address $address
	 *
	 * @return false|mixed|null
	 */
	private function getVatValidation( $address ) {
		$address = $this->getAddress( $address );
		if ( ! $address ) {
			return null;
		}

		$table = _DB_PREFIX_ . 'vatchecker';

		$sql = "SELECT * FROM {$table}
			WHERE id_address = {$address->id}
			    AND id_country = {$address->id_country}
			    AND vat_number = '{$address->vat_number}'
			";

		$result = Db::getInstance()->executeS( $sql );
		if ( ! $result ) {
			return null;
		}

		// Only one result.
		return reset( $result );
	}

	/**
	 * Update/Set VAT validation in the database.
	 *
	 * @since 1.3.0
	 *
	 * @throws PrestaShopDatabaseException
	 *
	 * @param array $record {
	 *     @type int    id_vatchecker
	 *     @type int    id_address (Required)
	 *     @type int    id_country (Required)
	 *     @type string company
	 *     @type string vat_number (Required)
	 *     @type bool   valid
	 *     @type string date_add
	 *     @type string date_modified
	 *     @type string date_valid_vat
	 * }
	 *
	 * @return array|bool|mysqli_result|PDOStatement|resource|null
	 */
	private function setVatValidation( $record ) {
		$table = _DB_PREFIX_ . 'vatchecker';

		// Required fields.
		if (
			empty( $record['id_address'] ) ||
			empty( $record['id_country'] ) ||
			empty( $record['vat_number'] )
		) {
			return false;
		}

		if ( empty( $record['id_vatchecker'] ) ) {
			$exists = $this->getVatValidation( $record['id_address'] );
			if ( $exists ) {
				$record['id_vatchecker'] = $exists['id_vatchecker'];
			}
		}

		$today = date( 'Y-m-d H:i:s' );
		$record['date_modified'] = $today;
		if ( $record['valid'] ) {
			$record['date_valid_vat'] = $today;
		}
		if ( empty( $record['date_add'] ) ) {
			$record['date_add'] = $today;
		}

		$keys   = array();
		$values = array();
		foreach ( $record as $key => $value ) {
			$keys[ $key ] = "`{$key}`";
			if ( is_bool( $value ) ) {
				$values[ $key ] = (int) $value;
			} else {
				$values[ $key ] = "'{$value}'";
			}
		}

		if ( ! empty( $record['id_vatchecker'] ) ) {
			// Update.
			$id = (int) $record['id_vatchecker'];
			foreach ( $values as $key => $value ) {
				$values[ $key ] = $keys[ $key ] . ' = ' . $value;
			}
			$values = implode( ', ', $values );
			$sql    = "UPDATE {$table} SET {$values} WHERE id_vatchecker = {$id}";
		} else {
			// Insert.
			$keys   = implode( ', ', $keys );
			$values = implode( ', ', $values );
			$sql    = "INSERT INTO {$table} ({$keys}) VALUES ({$values})";
		}

		return Db::getInstance()->executeS( $sql );
	}

	/**
	 * @since 1.1.0
	 *
	 * @param string     $vatNumber
	 * @param int|string $countryCode
	 * @param bool       $error  Return error string if errored?
	 *
	 * @return bool|null|string Optionally returns string on error if errors are enabled.
	 *                          Otherwise a boolean or null (disabled).
	 */
	public function checkVat( $vatNumber, $countryCode = null, $error = true ) {

		if ( ! Configuration::get( 'VATCHECKER_LIVE_MODE' ) ) {
			return null;
		}

		if ( is_numeric( $countryCode ) ) {
			$countryCode = Country::getIsoById( $countryCode );
		}

		if ( ! $this->isEUCountry( $countryCode ) ) {
			return ( $error ) ? $this->l('Please select an EU country') : false;
		}

		$vatNumber = ltrim( $vatNumber, $countryCode );

		if ( ! $this->isVatFormat( $vatNumber ) ) {
			return ( $error ) ? $this->l('VAT number format invalid') : false;
		}

		$valid = $this->checkVies( $countryCode, $vatNumber );
		if ( is_bool( $valid ) ) {
			if ( ! $valid && $error ) {
				// VIES validation returned false.
				$valid = $this->l('This is not a valid VAT number');
			}
		} elseif ( is_string( $valid ) && ! $error ) {
			// Convert VIES validation error to false.
			$valid = false;
		}
		return $valid;
	}

	/**
	 * @since 1.0
	 *
	 * @param string $countryCode
	 * @param string $vatNumber
	 *
	 * @return bool|null|string
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
			return false;

		} catch ( Throwable $e ) {
			if ( Configuration::get( 'VATCHECKER_ALLOW_OFFLINE' ) ) {
				return null;
			}

			//return $this->l( $e->getMessage() );
			return $this->l( 'EU VIES server not responding' );
		}
	}

	/**
	 * Check vat number format before calling VIES API.
	 *
	 * @since 1.3.0
	 *
	 * @param string $vatNumber
	 *
	 * @return bool
	 */
	public function isVatFormat( $vatNumber ) {

		$preg = array(
			'/(?xi)^(',
			'AT' => '(AT)?U[0-9]{8}',                              # Austria
			'BE' => '(BE)?0[0-9]{9}',                              # Belgium
			'BG' => '(BG)?[0-9]{9,10}',                            # Bulgaria
			'HR' => '(HR)?[0-9]{11}',                              # Croatia
			'CY' => '(CY)?[0-9]{8}[A-Z]',                          # Cyprus
			'CZ' => '(CZ)?[0-9]{8,10}',                            # Czech Republic
			'DE' => '(DE)?[0-9]{9}',                               # Germany
			'DK' => '(DK)?[0-9]{8}',                               # Denmark
			'EE' => '(EE)?[0-9]{9}',                               # Estonia
			'EL' => '(EL)?[0-9]{9}',                               # Greece
			'ES' => 'ES[A-Z][0-9]{7}(?:[0-9]|[A-Z])',              # Spain
			'FI' => '(FI)?[0-9]{8}',                               # Finland
			'FR' => '(FR)?[0-9A-Z]{2}[0-9]{9}',                    # France
			//'GB' => '(GB)?([0-9]{9}([0-9]{3})?|[A-Z]{2}[0-9]{3})', # United Kingdom
			'HU' => '(HU)?[0-9]{8}',                               # Hungary
			'IE' => '(IE)?[0-9]{7}[A-Z]{1,2}',                     # Ireland
			'IE2' => '(IE)?[0-9][A-Z][0-9]{5}[A-Z]',               # Ireland (2)
			'IT' => '(IT)?[0-9]{11}',                              # Italy
			'LT' => '(LT)?([0-9]{9}|[0-9]{12})',                   # Lithuania
			'LU' => '(LU)?[0-9]{8}',                               # Luxembourg
			'LV' => '(LV)?[0-9]{11}',                              # Latvia
			'MT' => '(MT)?[0-9]{8}',                               # Malta
			'NL' => '(NL)?[0-9]{9}B[0-9]{2}',                      # Netherlands
			'PL' => '(PL)?[0-9]{10}',                              # Poland
			'PT' => '(PT)?[0-9]{9}',                               # Portugal
			'RO' => '(RO)?[0-9]{2,10}',                            # Romania
			'SE' => '(SE)?[0-9]{12}',                              # Sweden
			'SI' => '(SI)?[0-9]{8}',                               # Slovenia
			'SK' => '(SK)?[0-9]{10}',                              # Slovakia
			')$/',
		);

		return (bool) preg_match( implode( '|', $preg ), $vatNumber );
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
	 * @since 1.2.2
	 * @param int|string $countryId
	 * @return bool
	 */
	public function isOriginCountry( $countryId ) {
		if ( ! is_numeric( $countryId ) ) {
			$country = Country::getByIso( $countryId );
			if ( ! isset( $country['id_country'] ) ) {
				return false;
			}
			$countryId = $country['id_country'];
		}
		return ( (int) Configuration::get( 'VATCHECKER_ORIGIN_COUNTRY' ) === (int) $countryId );
	}

	/**
	 * @param Address|int $address
	 * @return Address|null
	 */
	public function getAddress( $address ) {
		if ( is_numeric( $address ) ) {
			$address = new Address( $address );
		}
		if ( $address instanceof Address ) {
			return $address;
		}
		return null;
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
		if ( ! $this->getNoTaxGroup() ) {
			return;
		}

		if ( is_string( $vatValid ) ) {
			$vatValid = $this->checkVat( $vatValid, $countryId );

			if ( null === $vatValid ) {
				// Module inactive.
				return;
			}
		}

		if ( true === $vatValid ) {

			if ( ! $this->isOriginCountry( (int) $countryId ) ) {
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
	protected function addNoTaxGroup( $customer ) {
		$group = $this->getNoTaxGroup();
		if ( ! $group ) {
			return;
		}
		if ( $this->hasNoTaxGroup( $customer ) ) {
			// Already in group.
			return;
		}

		$customer->addGroups( array( (int) $group ) );
	}

	/**
	 * @since 1.1.0
	 * @param Customer $customer
	 */
	protected function removeNoTaxGroup( $customer ) {
		$group = $this->getNoTaxGroup();
		if ( ! $group ) {
			return;
		}
		if ( ! $this->hasNoTaxGroup( $customer ) ) {
			// Not in group.
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
