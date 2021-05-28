/**
 * 2021-now Keraweb
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
 *  @author    Keraweb <info@keraweb.nl>
 *  @copyright 2021-Now Keraweb
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 *
 * Don't forget to prefix your containers with your own identifier
 * to avoid any conflicts with others containers.
 */

jQuery( function( $ ) {

	vatchecker.validate = function( vat_number, id_country, $elem ) {
		$elem.removeClass( 'validated error text-danger text-success' );
		$elem.next( '.vat-result' ).remove();
		$elem.after( '<p class="vat-result small"></p>' );

		// Minimal VAT number length is 8 digits.
		// https://en.wikipedia.org/wiki/VAT_identification_number
		if ( ! vat_number || vat_number.length < 8 ) {
			return;
		}

		var $result          = $elem.next( '.vat-result' ).html( '. . . ' ),
			loading          = '. . . ',
			loading_interval = setInterval( function() {
			if ( 20 < loading.length ) {
				loading = '. . . ';
			}
			loading += '. ';
			$result.html( loading );
		}, 500 );

		$elem.css( { 'opacity': '0.5' } );

		$.ajax( {
			type: 'POST',
			url: vatchecker.ajax_url,
			headers: {"cache-control": "no-cache"},
			//async: false,
			data: {
				vatchecker: vatchecker.token,
				vat_number: vat_number,
				id_country: id_country,
			},
			dataType: 'json',
			success: function ( resp ) {
				clearInterval( loading_interval );
				$result.html('');
				if ( resp.hasOwnProperty( 'valid' ) ) {
					// Check successful.
					if ( true === resp.valid ) {
						// Valid VAT
						$elem.addClass( 'validated text-success' );
					} else {
						$elem.addClass( 'error text-danger' );
						if ( resp.hasOwnProperty( 'error' ) && resp.error ) {
							// Error message.
							$result.addClass( 'text-danger' ).html( resp.error );
						}
					}
				} else {
					// Fail
					$elem.addClass( 'error text-danger' );
				}
			}
		} ).always( function() {
			clearInterval( loading_interval );
			$elem.css( { 'opacity': '' } );
		} ).fail( function( resp ) {
			clearInterval( loading_interval );
			$result.html('');
			$elem.addClass( 'error text-danger' );
		} );
	};

	$(document).on( 'blur', '[name="vat_number"]', function () {
		var $this = $( this ),
			$form = $this.parents( 'form' ),
			$country = $form.find('[name="id_country"]');

		// Remove invalid characters.
		$this.val( $this.val().toUpperCase().replace( /[^A-Z0-9]/gi, '' ) );

		vatchecker.validate( $( this ).val(), $country.val(), $this );
	} );

} );
