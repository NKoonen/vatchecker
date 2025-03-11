<?php

class Carrier extends CarrierCore
{
	public function getTaxesRate(Address $address = null)
	{
		if (!$address || !$address->id_country) {
			$address = Address::initialize();
		}

		$vatchecker = Module::getInstanceByName( 'vatchecker' );
		if ( $vatchecker && $vatchecker->canOrderWithoutVat( $address ) && Configuration::get( 'VATCHECKER_CARRIER_NOTAX' ) ) {
			if(empty(Configuration::get( 'VATCHECKER_TAXRATE_RULE' )))
			{
				return 0;
			}

			$tax_manager = TaxManagerFactory::getManager($address, (int)Configuration::get( 'VATCHECKER_TAXRATE_RULE' ));

			return $tax_manager->getTaxCalculator($address)->getTotalRate();
		}
		$tax_calculator = $this->getTaxCalculator($address);

		return $tax_calculator->getTotalRate();
	}
}
