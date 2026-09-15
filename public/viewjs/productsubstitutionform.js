// Powers the product substitution create form (views/productsubstitutionform.blade.php), embedded in a modal
// from the product edit form: saves a directed edge via the objects/product_substitutions API. Create only - an
// edge has nothing to edit beyond which two products and which direction, so the product-form table offers
// delete, not edit.

// Form submit: POSTs objects/product_substitutions. The form only knows "this product" (fixed, hidden) and "the
// other product" (picked); direction says which side of the edge "this product" is on, so it is translated into
// from_product_id/to_product_id here rather than on the server.
$('#save-product-substitution-button').on('click', function (e)
{
	e.preventDefault();

	if (!Victual.FrontendHelpers.ValidateForm("product-substitution-form", true))
	{
		return;
	}

	if ($(".combobox-menu-visible").length)
	{
		return;
	}

	var jsonData = $('#product-substitution-form').serializeJSON();
	var thisProductId = jsonData.this_product_id;
	var otherProductId = jsonData.product_id;
	var direction = jsonData.direction;
	delete jsonData.this_product_id;
	delete jsonData.product_id;
	delete jsonData.direction;

	if (direction === 'this')
	{
		jsonData.from_product_id = thisProductId;
		jsonData.to_product_id = otherProductId;
	}
	else
	{
		jsonData.from_product_id = otherProductId;
		jsonData.to_product_id = thisProductId;
	}

	Victual.FrontendHelpers.BeginUiBusy("product-substitution-form");

	Victual.Api.Post('objects/product_substitutions', jsonData,
		function (result)
		{
			window.parent.postMessage(WindowMessageBag("ProductSubstitutionsChanged"), Victual.BaseUrl);
			window.parent.postMessage(WindowMessageBag("CloseLastModal"), Victual.BaseUrl);
		},
		function (xhr)
		{
			Victual.FrontendHelpers.EndUiBusy("product-substitution-form");
			Victual.FrontendHelpers.ShowGenericError('Error while saving, probably this substitution already exists', xhr.response);
		}
	);
});

// Enter submits the form (when valid)
$('#product-substitution-form input').keydown(function (event)
{
	if (event.keyCode === 13) // Enter
	{
		event.preventDefault();

		if (!Victual.FrontendHelpers.ValidateForm('product-substitution-form'))
		{
			return false;
		}
		else
		{
			$('#save-product-substitution-button').click();
		}
	}
});

Victual.FrontendHelpers.ValidateForm('product-substitution-form');
