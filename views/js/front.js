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
 *  @since     1.1.0
 *  International Registered Trademark & Property of PrestaShop SA
 *
 * Don't forget to prefix your containers with your own identifier
 * to avoid any conflicts with others containers.
 */

jQuery( function( $ ) {

	var $document = $(document),
		checked   = {};

	vatchecker.validate = function( vat_number, id_country, company, $elem, $reloader ) {
		$elem.removeClass( 'validated error text-danger text-success' );
		$elem.siblings( '.vatchecker-result' ).remove();

		var identifier = vat_number + '_' + id_country + '_' + company;

		// Remove invalid characters.
		vat_number = vat_number.toUpperCase().replace( /[^A-Z0-9]/gi, '' );
		$elem.val( vat_number );

		// Minimal VAT number length is 8 digits.
		// https://en.wikipedia.org/wiki/VAT_identification_number
		if ( ! vat_number || vat_number.length < 8 ) {
			return;
		}

		if ( ! $reloader || ! $reloader.length ) {
			$reloader = addReload( $elem );
		} else {
			// Enfore recheck.
			delete checked[ identifier ];
		}

		if ( $reloader ) {
			$reloader.after( '<div class="vatchecker-result small"></div>' );
		} else {
			$elem.after( '<div class="vatchecker-result small"></div>' );
		}

		var $result          = $elem.siblings( '.vatchecker-result' ),
			loading          = '. . . ',
			loading_interval = setInterval( function() {
			if ( 20 < loading.length ) {
				loading = '. . . ';
			}
			loading += '. ';
			$result.html( loading );
		}, 500 );

		if ( checked.hasOwnProperty( identifier ) ) {
			success( checked[ identifier ] );
			return;
		}

		$elem.css( { 'opacity': '0.5' } );
		$reloader.addClass( 'rotate' );

		$.ajax( {
			type: 'POST',
			url: vatchecker.ajax_url,
			headers: {"cache-control": "no-cache"},
			//async: false,
			data: {
				vatchecker: vatchecker.token,
				vat_number: vat_number,
				id_country: id_country,
				company: company,
			},
			dataType: 'json',
			success: function ( resp ) {
				success( resp );
			}
		} ).always( function() {
			clearInterval( loading_interval );
			$elem.css( { 'opacity': '' } );
		} ).fail( function( resp ) {
			clearInterval( loading_interval );
			$result.remove();
			$elem.addClass( 'error text-danger' );
		} );

		function success( resp ) {

			clearInterval( loading_interval );
			$result.html('');
			$reloader.removeClass( 'rotate' );
			if ( resp.hasOwnProperty( 'valid' ) ) {
				// Check successful.
				if ( resp.valid ) {
					// Valid VAT
					$elem.addClass( 'validated text-success' );
					$result.remove();
					$reloader.remove();

					checked[ identifier ] = resp;
				} else if ( resp.error ) {
					$elem.addClass( 'error text-danger' );
					// Error message.
					$result.addClass( 'text-danger' ).html( resp.error );
				} else {
					$elem.removeClass( 'validated error text-danger text-success' );
					$result.remove();
				}
			} else {
				// Fail
				$elem.addClass( 'error text-danger' );
				$result.remove();
			}
		}
	};

	function addReload( input ) {
		var $vat    = $( input ),
			$reloader = $vat.siblings( '.vatchecker-reload' );

		if ( $reloader.length ) {
			return $reloader;
		}

		$vat.after( '<span class="vatchecker-reload"></span>' );
		$reloader = $vat.siblings( '.vatchecker-reload' );

		$reloader.on( 'click touchend', function() {
			var $form    = $vat.parents( 'form' ),
				$country = $form.find('[name="id_country"]'),
				$company = $form.find('[name="company"]');

			vatchecker.validate( $vat.val(), $country.val(), $company.val(), $vat, $reloader );
		} );

		return $reloader;
	}

	$document.on( 'blur', '[name="vat_number"]', function () {
		var $vat     = $( this ),
			$form    = $vat.parents( 'form' ),
			$country = $form.find('[name="id_country"]'),
			$company = $form.find('[name="company"]');

		vatchecker.validate( $vat.val(), $country.val(), $company.val(), $vat );
	} );

	$document.on( 'change', '[name="id_country"]', function() {
		var $country = $( this ),
			$form    = $country.parents( 'form' ),
			$vat     = $form.find('[name="vat_number"]'),
			$company = $form.find('[name="company"]');

		vatchecker.validate( $vat.val(), $country.val(), $company.val(), $vat );
	} );

	$document.on( 'change', '[name="company"]', function() {
		var $company = $( this ),
			$form    = $company.parents( 'form' ),
			$vat     = $form.find('[name="vat_number"]'),
			$country = $form.find('[name="id_country"]');

		vatchecker.validate( $vat.val(), $country.val(), $company.val(), $vat );
	} );

} );
