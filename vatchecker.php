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
		$this->version = '1.2.3';
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
			PRIMARY KEY(`id`)
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

		$address = new Address( $address_id );

		$countryId = $address->id_country;
		$vatNumber = $address->vat_number;

		$cache_key = $countryId . $vatNumber;

		if ( isset( $cache[ $cache_key ] ) ) {
			$vatValid = true === $cache[ $cache_key ];
			$this->updateNoTaxGroup( $vatValid, $countryId, $this->context->customer );

			return;
		}

		$vatValid = $this->checkVat( $vatNumber, $countryId );

		if ( null === $vatValid ) {
			// Module inactive or VIES server offline.
			return;
		}

		$vatValid = true === $vatValid;

		$this->updateNoTaxGroup( $vatValid, $countryId, $this->context->customer );

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

		$form       = $params['form'];
		$countryId  = $form->getField('id_country')->getValue();
		if ( ! $form->getField('vat_number') ) {
			return true;
		}
		$vatNumber = $form->getField('vat_number')->getValue();

		$vatValid = $this->checkVat( $vatNumber, $countryId );

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
	 * Check if a VAT number is valid using the address data.
	 *
	 * @param Address|array $params {
	 *     @type Address $address
	 *     @type int     $addreddId
	 *     @type int     $countryId
	 *     @type string  $vatNumber
	 * }
	 *
	 * @return bool
	 */
	public function isValidVat( $params ) {
		if ( $params instanceof Address) {
			$address   = $params;
			$addressId = $address->id;
			$vatNumber = $address->vat_number;
			$countryId = $address->id_country;
		} elseif ( ! empty( $params['address'] ) && $params['address'] instanceof Address ) {
			$address = $params['address'];

			$addressId = $address->id;
			$vatNumber = $address->vat_number;
			$countryId = $address->id_country;

		} else {
			if ( empty( $params['vatNumber'] ) || empty( $params['countryId'] ) ) {
				return false;
			}
			$vatNumber = $params['vatNumber'];
			$countryId = $params['countryId'];
			$addressId = ! empty( $params['addressId'] ) ? $params['addressId'] : '';
			if ( ! $addressId ) {
				// Check VAT without DB storage.
				return $this->checkVat( $vatNumber, $countryId );
			}
			$address = new Address( $addressId );
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
		$result = $this->getVatValidation( $addressId, $countryId, $vatNumber );
		if ( $result ) {

			// VIES API already ran successfully within 24 hours.
			if ( strtotime( $result['date_modified'] ) > strtotime( '-1 day' ) ) {
				return (bool) $result['valid'];
			}
		} else {
			$result = array(
				'id_address'     => $addressId,
				'id_country'     => $countryId,
				'company'        => $address->company,
				'vat_number'     => $vatNumber,
				'valid'          => false,
				'date_add'       => '',
				'date_modified'  => '',
				'date_valid_vat' => '',
			);
		}

		$vatCheck = $this->checkVat( $vatNumber, $countryId );

		// Make sure it's a boolean, otherwise it's an error so we don't want to update the database.
		if ( is_bool( $vatCheck ) ) {
			$result['valid'] = $vatCheck;
			$this->setVatValidation( $result );
		}

		return $vatCheck;
	}

	/**
	 * @throws PrestaShopDatabaseException
	 *
	 * @param $countryId
	 * @param $vatNumber
	 * @param $addressId
	 *
	 * @return false|mixed|null
	 */
	private function getVatValidation( $addressId, $countryId, $vatNumber ) {
		if ( ! $addressId || ! $countryId || ! $vatNumber ) {
			return null;
		}

		$table = _DB_PREFIX_ . 'vatchecker';

		$sql = "SELECT * FROM {$table}
			WHERE id_address = {$addressId}
			    AND id_country = {$countryId}
			    AND vat_number = {$vatNumber}
			";

		$result = Db::getInstance()->executeS( $sql );
		if ( empty( $result ) ) {
			return null;
		}

		// Only one result.
		$result = reset( $result );

		$db_id_address = (int) $result['id_address'];
		$db_id_country = (int) $result['id_country'];
		$db_vat_number = $result['vat_number'];

		if (
			$addressId != $db_id_address ||
			$countryId != $db_id_country ||
			$vatNumber != $db_vat_number
		) {
			return null;
		}
		return $result;
	}

	/**
	 * @throws PrestaShopDatabaseException
	 *
	 * @param array $record {
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
	 *
	 * @return array|bool|mysqli_result|PDOStatement|resource|null
	 */
	private function setVatValidation( $record ) {
		$table = _DB_PREFIX_ . 'vatchecker';

		if ( empty( $record['id_vatchecker'] ) ) {
			$exists = $this->getVatValidation( $record['id_address'], $record['id_country'], $record['vat_number'] );
			if ( $exists ) {
				$record['id_vatchecker'] = $exists['id_vatchecker'];
			}
		}

		$today = date( 'Y-m-d H:i:s' );
		$result['date_modified'] = $today;
		if ( $result['valid'] ) {
			$result['date_valid_vat'] = $today;
		}
		if ( ! $result['date_add'] ) {
			$result['date_add'] = $today;
		}

		$keys = array();
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
				$values = $keys[ $key ] . ' = ' . $value;
			}
			$values = implode( ', ', $values );
			$sql    = "UPDATE {$table} SET {$values} WHERE id_vatchecker = {$id}";
		} else {
			// Insert.
			$keys   = implode( ', ', $keys );
			$values = implode( ', ', $values );
			$sql    = 'INSERT INTO {$table} ({$keys}) VALUES ({$values})';
		}

		return Db::getInstance()->executeS( $sql );
	}

	/**
	 * @since 1.1.0
	 * @param string     $vatNumber
	 * @param int|string $countryCode
	 * @param bool       $error  Return error string?
	 * @return bool|null
	 */
	public function checkVat( $vatNumber, $countryCode = null, $error = true ) {

		if ( ! Configuration::get( 'VATCHECKER_LIVE_MODE' ) ) {
			return null;
		}

		if ( ! is_string( $vatNumber ) || 8 > strlen( $vatNumber ) ) {
			return ( $error ) ? $this->l('VAT number format invalid') : false;
		}

		if ( is_numeric( $countryCode ) ) {
			$countryCode = Country::getIsoById( $countryCode );
		}

		if ( ! $this->isEUCountry( $countryCode ) ) {
			return ( $error ) ? $this->l('Please select an EU country') : false;
		}

		$vatNumber = ltrim( $vatNumber, $countryCode );

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
			return false;

		} catch ( Throwable $e ) {
			if ( Configuration::get( 'VATCHECKER_ALLOW_OFFLINE' ) ) {
				return null;
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
