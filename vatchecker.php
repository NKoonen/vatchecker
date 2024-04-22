<?php
/**
 * 2007-2021 PrestaShop
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
 * @copyright 2007-2021 PrestaShop SA
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

if ( ! defined( '_PS_VERSION_' ) ) {
	exit;
}

const ERROR_SEVERITY = 3;

class Vatchecker extends Module
{
	/**
	 * Cache for VIES API results.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private static $cache = [];

	/**
	 * EU VIES API SOAP url.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $_SOAPUrl = 'https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl';

	/**
	 * A list of all EU countries and their VAT formats.
	 *
	 * @since 2.0.0
	 * @var string[]
	 */
	private $euVatFormats = [
		'AT'  => '(AT)?U[0-9]{8}',                              # Austria
		'BE'  => '(BE)?0[0-9]{9}',                              # Belgium
		'BG'  => '(BG)?[0-9]{9,10}',                            # Bulgaria
		'CY'  => '(CY)?[0-9]{8}[A-Z]',                          # Cyprus
		'CZ'  => '(CZ)?[0-9]{8,10}',                            # Czech Republic
		'DE'  => '(DE)?[0-9]{9}',                               # Germany
		'DK'  => '(DK)?[0-9]{8}',                               # Denmark
		'EE'  => '(EE)?[0-9]{9}',                               # Estonia
		'GR'  => '(EL)?[0-9]{9}',                               # Greece
		'ES'  => '(ES)?[A-Z][0-9]{7}(?:[0-9]|[A-Z])',           # Spain
		'FI'  => '(FI)?[0-9]{8}',                               # Finland
		'FR'  => '(FR)?[0-9A-Z]{2}[0-9]{9}',                    # France
		//'GB' => '(GB)?([0-9]{9}([0-9]{3})?|[A-Z]{2}[0-9]{3})', # United Kingdom // Brexit!
		'HR'  => '(HR)?[0-9]{11}',                              # Croatia
		'HU'  => '(HU)?[0-9]{8}',                               # Hungary
		'IE'  => '(IE)?[0-9]{7}[A-Z]{1,2}',                     # Ireland
		'IE2' => '(IE)?[0-9][A-Z][0-9]{5}[A-Z]',               # Ireland (2)
		'IT'  => '(IT)?[0-9]{11}',                              # Italy
		'LT'  => '(LT)?([0-9]{9}|[0-9]{12})',                   # Lithuania
		'LU'  => '(LU)?[0-9]{8}',                               # Luxembourg
		'LV'  => '(LV)?[0-9]{11}',                              # Latvia
		'MT'  => '(MT)?[0-9]{8}',                               # Malta
		'NL'  => '(NL)?[0-9]{9}B[0-9]{2}',                      # Netherlands
		'PL'  => '(PL)?[0-9]{10}',                              # Poland
		'PT'  => '(PT)?[0-9]{9}',                               # Portugal
		'RO'  => '(RO)?[0-9]{2,10}',                            # Romania
		'SE'  => '(SE)?[0-9]{12}',                              # Sweden
		'SI'  => '(SI)?[0-9]{8}',                               # Slovenia
		'SK'  => '(SK)?[0-9]{10}',                              # Slovakia
		'XI'  => '(XI)?([0-9]{9}([0-9]{3})?|[A-Z]{2}[0-9]{3})',  # North Ireland
	];

	/**
	 * @inheritDoc
	 */
	public function __construct()
	{
		$this->name          = 'vatchecker';
		$this->tab           = 'billing_invoicing';
		$this->version       = '2.1.0';
		$this->author        = 'Inform-All & Keraweb';
		$this->need_instance = 1;

		/**
		 * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
		 */
		$this->bootstrap = true;

		parent::__construct();

		$this->displayName = $this->l( 'VAT Checker' );
		$this->description = $this->l( 'The module verifies whether a customer possesses a valid VAT EU number through the VIES VAT online service. Upon validation, it automatically applies a 0% tax rate to customers from the EU who are not from the same country as the shop.' );
		

		$this->ps_versions_compliancy = [ 'min' => '1.7', 'max' => _PS_VERSION_ ];
	}

	/**
	 * Don't forget to create update methods if needed:
	 * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
	 */
	public function install()
	{
		Configuration::updateValue( 'VATCHECKER_LIVE_MODE', true );
		Configuration::updateValue( 'VATCHECKER_ALLOW_OFFLINE', true );
		Configuration::updateValue( 'VATCHECKER_CUSTOMER_GROUP', false );
		Configuration::updateValue('VATCHECKER_EU_COUNTRIES', null );
		Configuration::updateValue('VATCHECKER_ORIGIN_COUNTRY', null );

		return parent::install()
		       && $this->installDB()
		       && $this->registerHook( 'displayHeader' )
		       && $this->registerHook( 'displayBeforeBodyClosingTag' )
		       && $this->registerHook( 'actionValidateCustomerAddressForm' );
	}

	/**
	 * @since 2.0.0
	 * @return bool
	 */
	public function installDB()
	{
		$sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'vatchecker' . '` (
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
		) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8';

		return Db::getInstance()->execute( $sql );
	}

	public function uninstall()
	{
		Configuration::deleteByName( 'VATCHECKER_LIVE_MODE' );
		Configuration::deleteByName( 'VATCHECKER_ALLOW_OFFLINE' );
		Configuration::deleteByName( 'VATCHECKER_ORIGIN_COUNTRY' );
		Configuration::deleteByName( 'VATCHECKER_EU_COUNTRIES' );
		Configuration::deleteByName( 'VATCHECKER_CUSTOMER_GROUP' );

		return parent::uninstall();
	}

	/**
	 * @since 1.1.0
	 *
	 * @param  array  $params
	 */
	public function hookDisplayHeader( $params )
	{
		$this->context->controller->addJS( $this->_path . 'views/js/front.js' );
		$this->context->controller->addCSS( $this->_path . 'views/css/front.css' );
	}

	/**
	 * @since 1.1.0
	 *
	 * @param  array  $params
	 */
	public function hookDisplayBeforeBodyClosingTag( $params )
	{
		$json = [
			'ajax_url' => $this->getPathUri() . 'ajax.php',
			'token'    => Tools::getToken( 'vatchecker' ),
		];

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
		if ( ( (bool) Tools::isSubmit( 'submitVatcheckerModule' ) ) == true ) {
			$this->postProcess();
		}

		$this->context->smarty->assign( 'module_dir', $this->_path );

		$output = $this->context->smarty->fetch( $this->local_path . 'views/templates/admin/configure.tpl' );
		$output = $output . $this->renderForm();

		return $output;
	}

	/**
	 * Save form data.
	 */
	protected function postProcess()
	{
		$form_values = $this->getConfigFormValues();

		foreach ( array_keys( $form_values ) as $key ) {
			if ( false !== strpos( $key, 'VATCHECKER_EU_COUNTRIES' ) ) {
				continue;
			}
			Configuration::updateValue( $key, Tools::getValue( $key ) );
		}

		$eu_countries = [];
		$countries    = $this->getEUCountries();
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
		$values = [
			'VATCHECKER_LIVE_MODE'      => Configuration::get( 'VATCHECKER_LIVE_MODE', null, null, null, true ),
			'VATCHECKER_ALLOW_OFFLINE'  => Configuration::get( 'VATCHECKER_ALLOW_OFFLINE', null, null, null, true ),
			'VATCHECKER_ORIGIN_COUNTRY' => Configuration::get( 'VATCHECKER_ORIGIN_COUNTRY', null, null, null, '0' ),
			'VATCHECKER_CUSTOMER_GROUP' => Configuration::get( 'VATCHECKER_CUSTOMER_GROUP', null, null, null, false ),
		];

		$countries = $this->getEnabledCountries( false );
		foreach ( $countries as $id => $iso ) {
			$values[ 'VATCHECKER_EU_COUNTRIES_' . $id ] = true;
		}

		return $values;
	}

	/**
	 * Create the form that will be displayed in the configuration of your module.
	 */
	protected function renderForm()
	{
		$helper = new HelperForm();

		$helper->show_toolbar             = false;
		$helper->table                    = $this->table;
		$helper->module                   = $this;
		$helper->default_form_language    = $this->context->language->id;
		$helper->allow_employee_form_lang = Configuration::get( 'PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0 );

		$helper->identifier    = $this->identifier;
		$helper->submit_action = 'submitVatcheckerModule';
		$helper->currentIndex  = $this->context->link->getAdminLink( 'AdminModules', false )
		                         . '&configure='
		                         . $this->name
		                         . '&tab_module='
		                         . $this->tab
		                         . '&module_name='
		                         . $this->name;
		$helper->token         = Tools::getAdminTokenLite( 'AdminModules' );

		$helper->tpl_vars = [
			'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
			'languages'    => $this->context->controller->getLanguages(),
			'id_language'  => $this->context->language->id,
		];

		return $helper->generateForm( [ $this->getConfigForm() ] );
	}

	/**
	 * Create the structure of your form.
	 */
	protected function getConfigForm()
	{
		$countries = $this->getEUCountries( false );
		foreach ( $countries as $key => $country ) {
			$countries[ $key ] = [
				'id'   => $country['id_country'],
				'name' => $country['name'] . ' (' . $country['iso_code'] . ')',
			];
		}

		$select_country = [
			0 => [
				'id'   => 0,
				'name' => $this->l( '- Select a country -' ),
			],
		];

		$customer_groups             = Group::getGroups( Context::getContext()->language->id );
		$customer_groups["disabled"] = [ "name" => "Disabled", "id_group" => 0 ];

		return [
			'form' => [
				'legend' => [
					'title' => $this->l( 'Settings' ),
					'icon'  => 'icon-cogs',
				],
				'input'  => [
					[
						'type'    => 'switch',
						'label'   => $this->l( 'Activate module' ),
						'name'    => 'VATCHECKER_LIVE_MODE',
						'is_bool' => true,
						'values'  => [
							[
								'id'    => 'active_on',
								'value' => true,
								'label' => $this->l( 'Enabled' ),
							],
							[
								'id'    => 'active_off',
								'value' => false,
								'label' => $this->l( 'Disabled' ),
							],
						],
					],
					[
						'type'    => 'radio',
						'label'   => $this->l( 'Offline validation' ),
						'name'    => 'VATCHECKER_ALLOW_OFFLINE',
						'required' => false,
						'desc'    => $this->l( 'What should be done when the VIES VAT service is offline?' ),
						'values' => [
							[
								'id' => 'invalid',
								'value' => 0,
								'label' => $this->l('Always mark VAT as invalid')
							],
							[
								'id' => 'valid',
								'value' => 1,
								'label' => $this->l('Always mark VAT as valid')
							],
							[
								'id' => 'exists_invalid',
								'value' => 2,
								'label' => $this->l('Use previous validation value, if not previously validated mark VAT as invalid')
							],
							[
								'id' => 'exists_valid',
								'value' => 3,
								'label' => $this->l('Use previous validation value, if not previously validated mark VAT as valid')
							]
						],
					],
					[
						'col'     => 3,
						'type'    => 'select',
						'desc'    => $this->l( 'Select your store location' ),
						'name'    => 'VATCHECKER_ORIGIN_COUNTRY',
						'label'   => $this->l( 'Origin country' ),
						'options' => [
							'query' => array_merge( $select_country, $countries ),
							'id'    => 'id',
							'name'  => 'name',
						],
					],
					[
						'col'      => 3,
						'type'     => 'checkbox',
						'desc'     => $this->l( 'Select EU countries that can order with 0% VAT' ),
						'name'     => 'VATCHECKER_EU_COUNTRIES',
						'label'    => $this->l( 'Enabled EU countries' ),
						'multiple' => true,
						'values'   => [
							'query' => $countries,
							'id'    => 'id',
							'name'  => 'name',
							'value' => 'id',
						],
					],
					[
						'type'     => 'select',
						'label'    => $this->l( 'Business customer group' ),
						'desc'     => $this->l( 'If a customer has a validated VAT EU number, assign them to the selected group. (OPTIONAL)' ),
						'name'     => 'VATCHECKER_CUSTOMER_GROUP',
						'required' => true,
						'options'  => [
							'query' => $customer_groups,
							'id'    => 'id_group',
							'name'  => 'name',
						],
						'data'     => 'Disabled',
					],

				],
				'submit' => [
					'title' => $this->l( 'Save' ),
				],
			],
		];
	}

	/**
	 * @since 1.0
	 *
	 * @param $params
	 *
	 * @return bool
	 */
	public function hookActionValidateCustomerAddressForm( &$params )
	{
		if ( empty( $params['form'] ) || ! Configuration::get( 'VATCHECKER_LIVE_MODE' ) ) {
			return true;
		}

		/** @var CustomerAddressFormCore $form */
		$form      = $params['form'];
		$countryId = $form->getField( 'id_country' )->getValue();

		// Check if this is an EU country and a VAT number field exists.
		if ( ! $this->isEnabledCountry( $countryId ) || ! $form->getField( 'vat_number' ) ) {
			return true;
		}

		$vatNumber = $form->getField( 'vat_number' )->getValue();

		// No value means we don't need to validate.
		if ( ! $vatNumber ) {
			return true;
		}

		$checkVat = $this->checkVat( $vatNumber, $countryId );
		$vatValid = $checkVat['valid'];
		$vatError = $checkVat['error'];

		if ( null === $vatValid ) {
			// Module inactive or VIES server offline.
			return true;
		}

		if ( true !== $vatValid ) {
			$form->getField( 'vat_number' )->addError( $vatError );

			return false;
		}

		$vat_validated_customer_group = (int) Configuration::get( 'VATCHECKER_CUSTOMER_GROUP' );
		if ( $checkVat['valid'] & $vat_validated_customer_group != 0 ) {
			$this->context->customer->addGroups( [ $vat_validated_customer_group ] );
		}

		return true;
	}

	/**
	 * Check if an address can order without VAT.
	 *
	 * @since 2.0.0
	 *
	 * @param  int|Address  $address
	 *
	 * @return bool
	 */
	public function canOrderWithoutVat( $address = null )
	{
		if ( ! $address ) {
			if ( $this->context->cart ) {
				$address = $this->context->cart->getTaxAddressId();
			}
		}
		$address = $this->getAddress( $address );
		if ( ! $address ) {
			return false;
		}

		if ( ! $this->isEnabledCountry( $address->id_country ) ) {
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
	 * @since 2.0.0
	 *
	 * @param  Address  $address
	 * @param  bool     $error  Return error (if any) or null (disabled) instead of boolean.
	 *
	 * @return bool|string Optionally returns string or null on error if errors are enabled.
	 */
	public function isValidVat( $address, $error = false )
	{
		$address = $this->getAddress( $address );
		if ( ! $address ) {
			return false;
		}

		$checkVat  = null;
		$cache_key = $address->id_country . $address->vat_number;
		if ( isset( self::$cache[ $cache_key ] ) ) {
			$checkVat = self::$cache[ $cache_key ];
		}

		if ( ! $checkVat ) {

			/**
			 * @var array $result {
			 * @type int    id_vatchecker
			 * @type int    id_address
			 * @type int    id_country
			 * @type string company
			 * @type string vat_number
			 * @type bool   valid
			 * @type string date_add
			 * @type string date_modified
			 * @type string date_valid_vat
			 *                    }
			 */
			$result = $this->getVatValidation( $address );

			if ( $result ) {

				// VIES API already ran successfully within 24 hours.
				if ( strtotime( $result['date_modified'] ) > strtotime( '-1 day' ) ) {
					$checkVat = [
						'valid' => (bool) $result['valid'],
						'error' => '',
					];
				}
			}

			if ( ! $checkVat ) {

				$result = [
					'id_address'     => $address->id,
					'id_country'     => $address->id_country,
					'company'        => $address->company,
					'vat_number'     => $address->vat_number,
					'valid'          => false,
					'date_add'       => '',
					'date_modified'  => '',
					'date_valid_vat' => '',
				];

				$checkVat = $this->checkVat( $address->vat_number, $address->id_country );

				if ( is_bool( $checkVat['valid'] ) ) {
					$result['valid'] = $checkVat['valid'];
					$this->setVatValidation( $result );
				}
			}
		}

		$vatValid = $checkVat['valid'];
		$vatError = $checkVat['error'];

		if ( $error ) {
			if ( $vatError ) {
				return $vatError;
			}
		} else {
			// Force boolean return: module or VIES offline.
			if ( null === $vatValid ) {
				return true;
			}
		}

		return $vatValid;
	}

	/**
	 * Get VAT validation from the database.
	 *
	 * @since 2.0.0
	 *
	 * @throws PrestaShopDatabaseException
	 *
	 * @param  Address  $address
	 *
	 * @return false|mixed|null
	 */
	private function getVatValidation( $address )
	{
		$address = $this->getAddress( $address );
		if ( ! $address || ! $address->id || ! $address->id_country || ! $address->vat_number ) {
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
	 * @since 2.0.0
	 *
	 * @throws PrestaShopDatabaseException
	 *
	 * @param  array  $record  {
	 *
	 * @type int    id_vatchecker
	 * @type int    id_address (Required)
	 * @type int    id_country (Required)
	 * @type string company
	 * @type string vat_number (Required)
	 * @type bool   valid
	 * @type string date_add
	 * @type string date_modified
	 * @type string date_valid_vat
	 *                         }
	 *
	 * @return array|bool|mysqli_result|PDOStatement|resource|null
	 */
	private function setVatValidation( $record )
	{
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

		$keys   = [];
		$values = [];
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

		return Db::getInstance()->execute( $sql );
	}

	/**
	 * Checks for valid VAT params and calls VIES API.
	 *
	 * @since 1.1.0
	 * @since 2.0.0 Returns array instead of scalar.
	 *
	 * @param  string      $vatNumber
	 * @param  int|string  $countryCode
	 *
	 * @return array {
	 * @type bool|null     $valid Boolean or null (disabled).
	 * @type string        $error Error notification (if any).
	 *                            }
	 */
	public function checkVat( $vatNumber, $countryCode )
	{
		$cache_key = $countryCode . $vatNumber;
		if ( isset( self::$cache[ $cache_key ] ) ) {
			return self::$cache[ $cache_key ];
		}

		if ( ! Configuration::get( 'VATCHECKER_LIVE_MODE' ) ) {
			return [
				'valid' => null,
				'error' => '',
			];
		}
		$return = [
			'valid' => false,
			'error' => '',
		];

		if ( is_numeric( $countryCode ) ) {
			$countryCode = Country::getIsoById( $countryCode );
		}

		if ( ! $countryCode || ! $this->isEUCountry( $countryCode ) ) {
			$return['error'] = $this->l( 'Please select an EU country' );

			self::$cache[ $cache_key ] = $return;

			return $return;
		}

		if ( ! $vatNumber ) {
			$return['error'] = $this->l( 'Please provide a VAT number' );

			self::$cache[ $cache_key ] = $return;

			return $return;
		}

		$vatNumber = ltrim( $vatNumber, $countryCode );

		if ( ! $this->isVatFormat( $vatNumber ) ) {
			$return['error'] = $this->l( 'VAT number format invalid' );

			self::$cache[ $cache_key ] = $return;

			return $return;
		}

		// Format validated, make the call!
		self::$cache[ $cache_key ] = $this->checkVies( $countryCode, $vatNumber );

		return self::$cache[ $cache_key ];
	}

	/**
	 * @since 1.0.0
	 * @since 2.0.0 Returns array instead of scalar.
	 *
	 * @param  string  $countryCode
	 * @param  string  $vatNumber
	 *
	 * @return array {
	 * @type bool|null $valid Boolean or null (disabled).
	 * @type string    $error Error notification (if any).
	 *                        }
	 */
	protected function checkVies( $countryCode, $vatNumber )
	{
		// Uses own static cache.
		static $cache = [];
		$cache_key = $countryCode . $vatNumber;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$return = [
			'valid' => false,
			'error' => '',
		];

		// @todo Centralize method?
		switch ( $countryCode ) {
			case 'GR':
				$countryCode = 'EL'; // The Greek use "Ellas" apparently.
			break;
			case 'GB':
				$countryCode = 'XI'; // Northern Ireland.
			break;
		}
		$vatNumber = ltrim( $vatNumber, $countryCode );

		$params = [
			'countryCode' => $countryCode,
			'vatNumber'   => $vatNumber,
		];

		try {

			$client = new SoapClient( $this->_SOAPUrl );

			$result = $client->__soapCall( 'checkVat', [ $params ] );

			if ( $result->valid === true ) {
				$return['valid'] = true;
			} else {
				$return['error'] = $this->l( 'This is not a valid VAT number' );
			}
		} catch ( Throwable $e ) {

			//$return['error'] = $this->l( $e->getMessage() );
			$return['error'] = $this->l( 'EU VIES server not responding' );
			$return['valid'] = $this->VIESOfflineLogicHander($params);

			PrestaShopLogger::addLog( 'VAT check failed! (params: ' . implode( ', ', $params ) . ' , error: ' . $e->getMessage() . ')', ERROR_SEVERITY );
		}

		$cache[ $cache_key ] = $return;

		return $return;
	}

	public function VIESOfflineLogicHander($params)
	{
		switch ( Configuration::get( 'VATCHECKER_ALLOW_OFFLINE' ) ) {
			case 1:
			case true:
				return true;
			case 2:
				$previous =  $this->getPreviousValidation($params);
				return (!empty($previous)) ? $previous->valid : false;
			case 3:
				$previous =  $this->getPreviousValidation($params);
				return (!empty($previous)) ? $previous->valid : true;
		}
		return false;
	}

	/**
	 * Gets previous validation by countryId and Vatnumber
	 *
	 * @since 2.1.0
	 *
	 * @param  string  $vatNumber
	 *
	 * @return bool
	 */
	private function getPreviousValidation( $params )
	{
		$table = _DB_PREFIX_ . 'vatchecker';
		$countryId = Country::getByIso( $params['countryCode'] );

		$sql = "SELECT * FROM {$table}
			WHERE id_country = {$countryId}
				AND vat_number = '{$params['vatNumber']}'
			";

		$result = Db::getInstance()->executeS( $sql );
		if ( ! $result ) {
			return null;
		}

		// Only one result.
		return reset( $result );
	}

	/**
	 * Check vat number format before calling VIES API.
	 *
	 * @since 2.0.0
	 *
	 * @param  string  $vatNumber
	 *
	 * @return bool
	 */
	public function isVatFormat( $vatNumber )
	{
		if ( ! $vatNumber ) {
			return false;
		}

		$formats = implode( '|', $this->euVatFormats );
		$preg    = '/(?xi)^(' . $formats . ')$/';

		return (bool) preg_match( $preg, $vatNumber );
	}

	/**
	 * @since 1.2.2
	 *
	 * @param  int|string|Country  $countryId
	 *
	 * @return bool
	 */
	public function isOriginCountry( $countryId )
	{
		return ( $this->getOriginCountryId() === $this->getCountryId( $countryId ) );
	}

	/**
	 * @since 2.0.0
	 *
	 * @param  int|string  $countryCode
	 *
	 * @return bool
	 */
	public function isEnabledCountry( $countryCode )
	{
		if ( is_numeric( $countryCode ) ) {
			$countryCode = Country::getIsoById( $countryCode );
		}

		return in_array( $countryCode, $this->getEnabledCountries() );
	}

	/**
	 * @since 1.1.0
	 *
	 * @param  int|string  $countryCode
	 *
	 * @return bool
	 */
	public function isEUCountry( $countryCode )
	{
		$key = 'iso_code';
		if ( is_numeric( $countryCode ) ) {
			$key = 'id_country';
		}
		foreach ( $this->getEUCountries() as $country ) {
			if ( $country[ $key ] === $countryCode ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @since 2.0.0
	 * @return int|null
	 */
	public function getOriginCountryId( $cache = true )
	{
		static $origin_country = null;
		if ( ! $cache || null === $origin_country ) {
			$origin_country = (int) Configuration::get( 'VATCHECKER_ORIGIN_COUNTRY' );
		}

		return $origin_country;
	}

	/**
	 * @since 2.0.0
	 * @return array
	 */
	public function getEnabledCountries( $cache = true )
	{
		static $countries = [];
		if ( $cache && $countries ) {
			return $countries;
		}
		$countries = json_decode( Configuration::get( 'VATCHECKER_EU_COUNTRIES' ), true );
		if ( ! $countries ) {
			foreach ( $this->getEUCountries() as $country ) {
				$countries[ $country['id_country'] ] = $country['iso_code'];
			}
		}

		return $countries;
	}

	/**
	 * @since 1.1.0
	 * @return array
	 */
	public function getEUCountries( $cache = true )
	{
		static $countries = [];
		if ( $cache && $countries ) {
			return $countries;
		}
		$all_countries = Country::getCountries( $this->context->language->id );
		$countries     = [];
		foreach ( $all_countries as $country ) {
			if ( array_key_exists( $country['iso_code'], $this->euVatFormats ) ) {
				//$country['vat_format'] = $this->euVatFormats[ $country['iso_code'] ];
				$countries[ $country['id_country'] ] = $country;
			}
		}

		return $countries;
	}

	/**
	 * Get country ID. Wrapper for Country::getByIso since it doesn't utilize cache.
	 *
	 * @param  mixed  $country
	 *
	 * @return int
	 */
	public function getCountryId( $country )
	{
		static $cache = [];

		if ( ! is_scalar( $country ) ) {
			if ( $country instanceof Country ) {
				return $country->id;
			} elseif ( isset( $country['id_country'] ) ) {
				return $country['id_country'];
			}
		}

		if ( isset( $cache[ $country ] ) ) {
			return $cache[ $country ];
		}

		$countryId = 0;
		if ( is_numeric( $country ) ) {
			$countryId = $country;
		} else {
			if ( is_string( $country ) ) {
				$countryId = Country::getByIso( $country );
				if ( is_array( $countryId ) && isset( $countryId['id_country'] ) ) {
					$countryId = $countryId['id_country'];
				}
			}
			if ( ! is_numeric( $countryId ) ) {
				$cache[ $country ] = null;

				return false;
			}
		}
		$cache[ $country ] = (int) $countryId;

		return $cache[ $country ];
	}

	/**
	 * @since 2.0.0
	 *
	 * @param  Address|int  $address
	 *
	 * @return Address|null
	 */
	public function getAddress( $address )
	{
		if ( is_numeric( $address ) ) {
			$address = new Address( $address );
		}
		if ( $address instanceof Address ) {
			return $address;
		}

		return null;
	}
}
