(function ($) {
	"use strict";
	if (!$) return;

	$(function () {
		var $aliases = $("#AUVORECHNUNGSIMPORT_OWN_COMPANY_ALIASES");
		if (!$aliases.length) return;
		if ($.fn.select2 && !$aliases.hasClass("select2-hidden-accessible")) {
			$aliases.select2({
				tags: true,
				tokenSeparators: [";"],
				width: "resolve",
				placeholder: $aliases.data("placeholder"),
				dropdownCssClass: "ui-dialog"
			});
		}

		var $input = $("#auvorechnungsimport-new-alias");
		var addAliases = function () {
			var raw = $input.val() || "";
			raw.split(/[;\r\n]+/).forEach(function (alias) {
				alias = $.trim(alias);
				if (!alias) return;
				var $option = $aliases.find("option").filter(function () { return this.value === alias; }).first();
				if ($option.length) {
					$option.prop("selected", true);
				} else {
					$aliases.append(new Option(alias, alias, true, true));
				}
			});
			$aliases.trigger("change");
			$input.val("");
		};

		$("#auvorechnungsimport-add-alias").on("click", addAliases);
		$input.on("keydown", function (event) {
			if (event.key === "Enter") {
				event.preventDefault();
				addAliases();
			}
		});
	});
})(window.jQuery);
