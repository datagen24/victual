// Powers the stock settings section (stocksettings.blade.php): initializes all inputs/
// checkboxes from Victual.UserSettings (persistence itself is handled generically
// elsewhere by the .user-setting-control click handler, not in this file).
$("#product_presets_location_id").val(Victual.UserSettings.product_presets_location_id);
$("#product_presets_product_group_id").val(Victual.UserSettings.product_presets_product_group_id);
$("#product_presets_qu_id").val(Victual.UserSettings.product_presets_qu_id);
$("#product_presets_default_due_days").val(Victual.UserSettings.product_presets_default_due_days);

if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_STOCK_PRODUCT_OPENED_TRACKING && BoolVal(Victual.UserSettings.product_presets_treat_opened_as_out_of_stock))
{
	$("#product_presets_treat_opened_as_out_of_stock").prop("checked", true);
}

if (Victual.FeatureFlags.VICTUAL_FEATURE_FLAG_LABELS)
{
	$("#product_presets_default_stock_label_type").val(Victual.UserSettings.product_presets_default_stock_label_type);
}

$("#stock_due_soon_days").val(Victual.UserSettings.stock_due_soon_days);
$("#stock_default_purchase_amount").val(Victual.UserSettings.stock_default_purchase_amount);
$("#stock_default_consume_amount").val(Victual.UserSettings.stock_default_consume_amount);
$("#stock_decimal_places_amounts").val(Victual.UserSettings.stock_decimal_places_amounts);
$("#stock_decimal_places_prices_input").val(Victual.UserSettings.stock_decimal_places_prices_input);
$("#stock_decimal_places_prices_display").val(Victual.UserSettings.stock_decimal_places_prices_display);

if (BoolVal(Victual.UserSettings.show_icon_on_stock_overview_page_when_product_is_on_shopping_list))
{
	$("#show_icon_on_stock_overview_page_when_product_is_on_shopping_list").prop("checked", true);
}

if (BoolVal(Victual.UserSettings.stock_overview_show_all_out_of_stock_products))
{
	$("#stock_overview_show_all_out_of_stock_products").prop("checked", true);
}

if (BoolVal(Victual.UserSettings.show_purchased_date_on_purchase))
{
	$("#show_purchased_date_on_purchase").prop("checked", true);
}

if (BoolVal(Victual.UserSettings.show_warning_on_purchase_when_due_date_is_earlier_than_next))
{
	$("#show_warning_on_purchase_when_due_date_is_earlier_than_next").prop("checked", true);
}

if (BoolVal(Victual.UserSettings.stock_default_consume_amount_use_quick_consume_amount))
{
	$("#stock_default_consume_amount_use_quick_consume_amount").prop("checked", true);
	$("#stock_default_consume_amount").attr("disabled", "");
}

if (BoolVal(Victual.UserSettings.stock_auto_decimal_separator_prices))
{
	$("#stock_auto_decimal_separator_prices").prop("checked", true);
}

RefreshLocaleNumberInput();

// Enable/disable the default consume amount input depending on whether the "use quick
// consume amount instead" setting is on
$("#stock_default_consume_amount_use_quick_consume_amount").on("click", function()
{
	if (this.checked)
	{
		$("#stock_default_consume_amount").attr("disabled", "");
	}
	else
	{
		$("#stock_default_consume_amount").removeAttr("disabled");
	}
});
