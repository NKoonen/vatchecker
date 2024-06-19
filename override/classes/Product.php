<?php

class Product extends ProductCore
{
	public static function getIdTaxRulesGroupByIdProduct( $id_product, Context $context = null )
	{
		if ( ! $context ) {
			$context = Context::getContext();
		}
		if(!empty($context->cart->id_address_delivery))
		{
			$delivery_address = new Address( $context->cart->id_address_delivery );
		}else{
			$delivery_address = false;
		}

		$key = 'product_id_tax_rules_group_' . (int) $id_product . '_' . (int) $context->shop->id;

		if ( $delivery_address ) {
			/** @var Vatchecker $vatchecker */
			$vatchecker = Module::getInstanceByName( 'vatchecker' );
			if ( $vatchecker && $vatchecker->canOrderWithoutVat( $delivery_address ) ) {
				// VatChecker module is used.
				$skipProduct = Db::getInstance()->getRow(
					'SELECT `id_product`
        			 FROM `' . _DB_PREFIX_ . 'vatchecker_excluded_products`
                     WHERE `id_product` =' . (int) $id_product
				);
				if ( empty($skipProduct) ) {
					$taxRuleGroupId = Configuration::get( 'VATCHECKER_TAXRATE_RULE' );
					if ( ! Cache::isStored( $key ) ) {
						Cache::store( $key, (int) $taxRuleGroupId );
					}

					return (int) $taxRuleGroupId;
				}
			}
		}

		if ( ! Cache::isStored( $key ) ) {
			$result = Db::getInstance( _PS_USE_SQL_SLAVE_ )->getValue(
				'
					SELECT `id_tax_rules_group`
					FROM `' . _DB_PREFIX_ . 'product_shop`
					WHERE `id_product` = ' . (int) $id_product . ' AND id_shop=' . (int) $context->shop->id
			);
			Cache::store( $key, (int) $result );

			return (int) $result;
		}

		return Cache::retrieve( $key );
	}
}
