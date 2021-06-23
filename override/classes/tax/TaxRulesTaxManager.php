<?php
/**
 * 2007-2019 PrestaShop and Contributors
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2019 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

/**
 * @inheritDoc
 */
class TaxRulesTaxManager extends TaxRulesTaxManagerCore
{
	/**
	 * Return the tax calculator associated to this address.
	 *
	 * @return TaxCalculator
	 */
	public function getTaxCalculator()
	{
		static $tax_enabled = null;

		if ( isset( $this->tax_calculator ) ) {
			return $this->tax_calculator;
		}

		if ( null === $tax_enabled ) {
			$tax_enabled = Configuration::get('PS_TAX');

			/** @var Vatchecker $vatchecker */
			$vatchecker    = Module::getInstanceByName('vatchecker');
			if ( $vatchecker ) {

				// Check if the customer is part of the no TAX group.
				$hasNoTaxGroup = $vatchecker->hasNoTaxGroup( $this->address->id_customer );
				
				// Crezzur: Added additional check to see if vatnumber is not empty when user is in NoTaxGroup.
				if ( $hasNoTaxGroup && !$this->address->vat_number ) {

					$tax_enabled = null; // If no TAX number = Pay taxes

				} else if ( $hasNoTaxGroup ) {

					// Double-check if the address isn't the same as the origin country.
					$isOriginCountry = $vatchecker->isOriginCountry( $this->address->id_country );
					if ( ! $isOriginCountry ) {

						// Disable TAX.
						$tax_enabled = false;
					}
				}
			}
		}

		if ( ! $tax_enabled ) {
			return new TaxCalculator( array() );
		}

		return parent::getTaxCalculator();
	}
}
