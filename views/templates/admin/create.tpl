<div class="row">
	<div class="col-lg-12 col-xl-4">
		<fieldset style="border:none;">
			<div class="form-group">
				<div>
					<h2>{l s='Exclude 0% tax' mod='vatchecker'}</h2>
					<p>{l s='This selection allows you to always force VAT on this product even if a customer has a valid VAT number.' mod='vatchecker'}</p>
					<input type="checkbox" name="vatchecker_exclude" id="vatchecker_exclude" class="form-control" {if $vatcheckerExclude}checked="checked"{/if} value="{$vatcheckerExclude}"/>
				</div>
			</div>
		</fieldset>
	</div>
</div>
