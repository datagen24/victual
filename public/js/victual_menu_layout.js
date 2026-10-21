// Sidebar menu behavior: collapse/expand toggling of the left side navigation,
// persisting the state in localStorage (key "sidebar_state" = "collapsed" | "expanded"),
// tooltips for collapsed menu items and scrolling the active item into view.
// Loaded on every page as part of the shared layout.

// Sidenav item tooltips need a custom template class so they can be
// styled/hidden depending on the collapsed state
$('.navbar-sidenav [data-toggle="tooltip"]').tooltip({
	template: '<div class="tooltip navbar-sidenav-tooltip"><div class="arrow"></div><div class="tooltip-inner"></div></div>'
})

// Toggle the sidebar between collapsed (icons only) and expanded,
// remembering the choice in localStorage ("sidebar_state")
$("#sidenavToggler").click(function(e)
{
	e.preventDefault();
	$("body").toggleClass("sidenav-toggled");
	$(".navbar-sidenav .nav-link-collapse").addClass("collapsed").attr("aria-expanded", "false");
	$(".navbar-sidenav .sidenav-second-level, .navbar-sidenav .sidenav-third-level").removeClass("show");

	if ($("body").hasClass("sidenav-toggled"))
	{
		window.localStorage.setItem("sidebar_state", "collapsed");
		$(".container-fluid").removeClass("pl-md-3");
	}
	else
	{
		window.localStorage.setItem("sidebar_state", "expanded");
		$(".container-fluid").addClass("pl-md-3");
	}
});

// Opening a collapsible submenu always expands the sidebar
$(".navbar-sidenav .nav-link-collapse").click(function(e)
{
	e.preventDefault();
	$("body").removeClass("sidenav-toggled");
	window.localStorage.setItem("sidebar_state", "expanded");

	// The shared click handler in victual.js blurs every clicked link once this one has run.
	// A submenu trigger is a disclosure control: a keyboard user who opens it continues from it.
	var trigger = this;
	setTimeout(function() { trigger.focus(); }, 0);
});

// A collapsible submenu trigger is a link, which the browser activates on Enter only.
// Space activates it as well, as it does for the button role the trigger announces.
$(".navbar-sidenav .nav-link-collapse").on("keydown", function(e)
{
	if (e.key === " " || e.key === "Spacebar")
	{
		e.preventDefault();
		this.click();
	}
});

// Restore the persisted collapsed state on page load
if (window.localStorage.getItem("sidebar_state") === "collapsed")
{
	$("#sidenavToggler").click();
}

// Make sure the current active menu item is visible
var activeMenuItem = $("li.active-page");
if (activeMenuItem.length > 0)
{
	if (!activeMenuItem.isVisibleInViewport(75))
	{
		activeMenuItem[0].scrollIntoView();
	}
}
