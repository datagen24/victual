@php global $VICTUAL_REQUIRED_FRONTEND_PACKAGES; @endphp

<!DOCTYPE html>
<html lang="{{ VICTUAL_LOCALE }}"
	dir="{{ $dir }}">

<head>
	<meta charset="utf-8">
	<meta name="viewport"
		content="width=device-width, initial-scale=1">
	<meta name="robots"
		content="noindex,nofollow">

	<link rel="icon"
		type="image/png"
		sizes="32x32"
		href="{{ $U('/img/icon-32.png?v=', true) }}{{ $version }}">

	@if (VICTUAL_AUTHENTICATED)
	<link rel="manifest"
		crossorigin="use-credentials"
		href="{{ $U('/manifest') . '?data=' . base64_encode($__env->yieldContent('title') . '#' . $U($_SERVER['REQUEST_URI'])) }}">
	@endif

	<title>@yield('title') | Victual</title>

	<link href="{{ $U('/packages/@fontsource/roboto/400.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	<link href="{{ $U('/packages/@fontsource/roboto/500.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	<link href="{{ $U('/packages/@fontsource/roboto/700.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	<link href="{{ $U('/packages/bootstrap/dist/css/bootstrap.min.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	<link href="{{ $U('/packages/@fortawesome/fontawesome-free/css/fontawesome.min.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	<link href="{{ $U('/packages/@fortawesome/fontawesome-free/css/solid.min.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	<link href="{{ $U('/packages/toastr/build/toastr.min.css?v=', true) }}{{ $version }}"
		rel="stylesheet">

	@if(in_array('bootstrap-combobox', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<link href="{{ $U('/packages/@danielfarrell/bootstrap-combobox/css/bootstrap-combobox.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	@endif
	@if(in_array('bootstrap-select', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<link href="{{ $U('/packages/bootstrap-select/dist/css/bootstrap-select.min.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	@endif
	@if(in_array('datatables', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<link href="{{ $U('/packages/datatables.net-bs4/css/dataTables.bootstrap4.min.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	<link href="{{ $U('/packages/datatables.net-colreorder-bs4/css/colReorder.bootstrap4.min.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	<link href="{{ $U('/packages/datatables.net-rowgroup-bs4/css/rowGroup.bootstrap4.min.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	<link href="{{ $U('/packages/datatables.net-select-bs4/css/select.bootstrap4.min.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	@endif
	@if(in_array('tempusdominus', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<link href="{{ $U('/packages/tempusdominus-bootstrap-4/build/css/tempusdominus-bootstrap-4.min.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	@endif
	@if(in_array('summernote', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<link href="{{ $U('/packages/summernote/dist/summernote-bs4.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	@endif
	@if(in_array('animatecss', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<link href="{{ $U('/packages/animate.css/animate.min.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	@endif
	@if(in_array('fullcalendar', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<link href="{{ $U('/packages/fullcalendar/dist/fullcalendar.min.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	@endif
	@if(in_array('daterangepicker', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<link href="{{ $U('/packages/daterangepicker/daterangepicker.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	@endif

	<link href="{{ $U('/css/victual_menu_layout.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	<link href="{{ $U('/css/victual.css?v=', true) }}{{ $version }}"
		rel="stylesheet">

	@if(boolval($userSettings['night_mode_enabled_internal']))
	<link id="night-mode-stylesheet"
		href="{{ $U('/css/victual_night_mode.css?v=', true) }}{{ $version }}"
		rel="stylesheet">
	@endif

	@stack('pageStyles')

	@if(file_exists(VICTUAL_DATAPATH . '/custom_css.html'))
	@php include VICTUAL_DATAPATH . '/custom_css.html' @endphp
	@endif
	<script>
		var Victual = { };
		Victual.Components = { };
		Victual.Mode = '{{ VICTUAL_MODE }}';
		Victual.BaseUrl = '{{ $U('/') }}';
		Victual.CurrentUrlRelative = "/" + window.location.href.split('?')[0].replace(Victual.BaseUrl, "");
		Victual.View = '{{ $viewName }}';
		Victual.Currency = '{{ VICTUAL_CURRENCY }}';
		Victual.EnergyUnit = '{{ VICTUAL_ENERGY_UNIT }}';
		Victual.CalendarFirstDayOfWeek = '{{ VICTUAL_CALENDAR_FIRST_DAY_OF_WEEK }}';
		Victual.CalendarShowWeekNumbers = {{ BoolToString(VICTUAL_CALENDAR_SHOW_WEEK_OF_YEAR) }};
		Victual.LocalizationStrings = {!! $LocalizationStrings !!};
		Victual.LocalizationStringsQu = {!! $LocalizationStringsQu !!};
		Victual.FeatureFlags = {!! json_encode($featureFlags) !!};
		Victual.Webhooks = {
		@if(VICTUAL_FEATURE_FLAG_LABEL_PRINTER && !VICTUAL_LABEL_PRINTER_RUN_SERVER)
			"labelprinter" : {
				"hook": "{{ VICTUAL_LABEL_PRINTER_WEBHOOK }}",
				"extra_data": {!! json_encode(VICTUAL_LABEL_PRINTER_PARAMS) !!},
				"json": {{ BoolToString(VICTUAL_LABEL_PRINTER_HOOK_JSON) }}
			}
		@endif
		};

		@if (VICTUAL_AUTHENTICATED)
		Victual.UserId = {{ VICTUAL_USER_ID }};
		Victual.UserSettings = {!! json_encode($userSettings) !!};
		Victual.UserPermissions = {!! json_encode($permissions) !!};
		@else
		Victual.UserId = -1;
		Victual.UserSettings = { };
		@endif
	</script>
</head>

<body class="fixed-nav @if(boolval($userSettings['night_mode_enabled_internal'])) night-mode @endif @if($embedded) embedded @endif">
	@if(!$embedded)
	<nav id="mainNav"
		class="navbar navbar-expand-lg navbar-light fixed-top">
		<a class="navbar-brand py-0"
			href="{{ $U('/') }}">
			<img src="{{ $U('/img/logo.svg?v=', true) }}{{ $version }}"
				width="75"
				height="30">
		</a>
		<span id="clock-container"
			class="text-muted font-italic d-none">
			<i class="fa-solid fa-clock"></i>
			<span id="clock-small"
				class="d-inline d-sm-none"></span>
			<span id="clock-big"
				class="d-none d-sm-inline"></span>
		</span>

		@if(VICTUAL_AUTHENTICATED)
		<button class="navbar-toggler navbar-toggler-right"
			type="button"
			data-toggle="collapse"
			data-target="#sidebarResponsive">
			<span class="navbar-toggler-icon"></span>
		</button>

		<div id="sidebarResponsive"
			class="collapse navbar-collapse">
			<ul class="navbar-nav navbar-sidenav">

				@if(VICTUAL_FEATURE_FLAG_STOCK && \Victual\Controllers\Users\User::HasPermissions(\Victual\Controllers\Users\User::PERMISSION_STOCK_VIEW))
				<li class="nav-item nav-item-sidebar @if($viewName == 'stockoverview') active-page @endif"
					data-toggle="tooltip"
					data-placement="right"
					title="{{ $__t('Stock overview') }}">
					<a class="nav-link discrete-link"
						href="{{ $U('/stockoverview') }}">
						<i class="fa-solid fa-fw fa-box"></i>
						<span class="nav-link-text">{{ $__t('Stock overview') }}</span>
					</a>
				</li>
				@endif
				@if(VICTUAL_FEATURE_FLAG_SHOPPINGLIST && \Victual\Controllers\Users\User::HasPermissions(\Victual\Controllers\Users\User::PERMISSION_SHOPPINGLIST_VIEW))
				<li class="nav-item nav-item-sidebar @if($viewName == 'shoppinglist') active-page @endif"
					data-toggle="tooltip"
					data-placement="right"
					title="{{ $__t('Shopping list') }}">
					<a class="nav-link discrete-link"
						href="{{ $U('/shoppinglist') }}">
						<i class="fa-solid fa-fw fa-shopping-cart"></i>
						<span class="nav-link-text">{{ $__t('Shopping list') }}</span>
					</a>
				</li>
				@endif
				@if(VICTUAL_FEATURE_FLAG_RECIPES && \Victual\Controllers\Users\User::HasPermissions(\Victual\Controllers\Users\User::PERMISSION_RECIPES_VIEW))
				<div class="nav-item-divider"></div>
				<li class="nav-item nav-item-sidebar permission-RECIPES @if($viewName == 'recipes') active-page @endif"
					data-toggle="tooltip"
					data-placement="right"
					title="{{ $__t('Recipes') }}">
					<a class="nav-link discrete-link"
						href="{{ $U('/recipes') }}">
						<i class="fa-solid fa-fw fa-pizza-slice"></i>
						<span class="nav-link-text">{{ $__t('Recipes') }}</span>
					</a>
				</li>
				@if(VICTUAL_FEATURE_FLAG_RECIPES_MEALPLAN && \Victual\Controllers\Users\User::HasPermissions(\Victual\Controllers\Users\User::PERMISSION_MEALPLAN_VIEW))
				<li class="nav-item nav-item-sidebar permission-RECIPES_MEALPLAN @if($viewName == 'mealplan') active-page @endif"
					data-toggle="tooltip"
					data-placement="right"
					title="{{ $__t('Meal plan') }}">
					<a id="meal-plan-nav-link"
						class="nav-link discrete-link"
						href="{{ $U('/mealplan') }}">
						<i class="fa-solid fa-fw fa-paper-plane"></i>
						<span class="nav-link-text">{{ $__t('Meal plan') }}</span>
					</a>
				</li>
				@endif
				@endif
				@if(VICTUAL_FEATURE_FLAG_CHORES && \Victual\Controllers\Users\User::HasPermissions(\Victual\Controllers\Users\User::PERMISSION_CHORES_VIEW))
				<div class="nav-item-divider"></div>
				<li class="nav-item nav-item-sidebar @if($viewName == 'choresoverview') active-page @endif"
					data-toggle="tooltip"
					data-placement="right"
					title="{{ $__t('Chores overview') }}">
					<a class="nav-link discrete-link"
						href="{{ $U('/choresoverview') }}">
						<i class="fa-solid fa-fw fa-home"></i>
						<span class="nav-link-text">{{ $__t('Chores overview') }}</span>
					</a>
				</li>
				@endif
				@if(VICTUAL_FEATURE_FLAG_TASKS && \Victual\Controllers\Users\User::HasPermissions(\Victual\Controllers\Users\User::PERMISSION_TASKS_VIEW))
				<li class="nav-item nav-item-sidebar @if($viewName == 'tasks') active-page @endif"
					data-toggle="tooltip"
					data-placement="right"
					title="{{ $__t('Tasks') }}">
					<a class="nav-link discrete-link"
						href="{{ $U('/tasks') }}">
						<i class="fa-solid fa-fw fa-tasks"></i>
						<span class="nav-link-text">{{ $__t('Tasks') }}</span>
					</a>
				</li>
				@endif
				@if(VICTUAL_FEATURE_FLAG_BATTERIES)
				<li class="nav-item nav-item-sidebar @if($viewName == 'batteriesoverview') active-page @endif"
					data-toggle="tooltip"
					data-placement="right"
					title="{{ $__t('Batteries overview') }}">
					<a class="nav-link discrete-link"
						href="{{ $U('/batteriesoverview') }}">
						<i class="fa-solid fa-fw fa-battery-half"></i>
						<span class="nav-link-text">{{ $__t('Batteries overview') }}</span>
					</a>
				</li>
				@endif
				@if(VICTUAL_FEATURE_FLAG_EQUIPMENT)
				<li class="nav-item nav-item-sidebar permission-EQUIPMENT @if($viewName == 'equipment') active-page @endif"
					data-toggle="tooltip"
					data-placement="right"
					title="{{ $__t('Equipment') }}">
					<a class="nav-link discrete-link"
						href="{{ $U('/equipment') }}">
						<i class="fa-solid fa-fw fa-toolbox"></i>
						<span class="nav-link-text">{{ $__t('Equipment') }}</span>
					</a>
				</li>
				@endif
				@if(VICTUAL_FEATURE_FLAG_CALENDAR)
				<div class="nav-item-divider"></div>
				<li class="nav-item nav-item-sidebar permission-CALENDAR @if($viewName == 'calendar') active-page @endif"
					data-toggle="tooltip"
					data-placement="right"
					title="{{ $__t('Calendar') }}">
					<a class="nav-link discrete-link"
						href="{{ $U('/calendar') }}">
						<i class="fa-solid fa-fw fa-calendar-days"></i>
						<span class="nav-link-text">{{ $__t('Calendar') }}</span>
					</a>
				</li>
				@endif

				@if(VICTUAL_FEATURE_FLAG_STOCK && \Victual\Controllers\Users\User::HasPermissions(\Victual\Controllers\Users\User::PERMISSION_STOCK_VIEW))
				<div class="nav-item-divider"></div>
				<li class="nav-item nav-item-sidebar permission-STOCK_PURCHASE @if($viewName == 'purchase') active-page @endif"
					data-toggle="tooltip"
					data-placement="right"
					title="{{ $__t('Purchase') }}">
					<a class="nav-link discrete-link"
						href="{{ $U('/purchase') }}">
						<i class="fa-solid fa-fw fa-cart-plus"></i>
						<span class="nav-link-text">{{ $__t('Purchase') }}</span>
					</a>
				</li>
				<li class="nav-item nav-item-sidebar permission-STOCK_CONSUME @if($viewName == 'consume') active-page @endif"
					data-toggle="tooltip"
					data-placement="right"
					title="{{ $__t('Consume') }}">
					<a class="nav-link discrete-link"
						href="{{ $U('/consume') }}">
						<i class="fa-solid fa-fw fa-utensils"></i>
						<span class="nav-link-text">{{ $__t('Consume') }}</span>
					</a>
				</li>
				@if(VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
				<li class="nav-item nav-item-sidebar permission-STOCK_TRANSFER @if($viewName == 'transfer') active-page @endif"
					data-toggle="tooltip"
					data-placement="right"
					title="{{ $__t('Transfer') }}">
					<a class="nav-link discrete-link"
						href="{{ $U('/transfer') }}">
						<i class="fa-solid fa-fw fa-exchange-alt"></i>
						<span class="nav-link-text">{{ $__t('Transfer') }}</span>
					</a>
				</li>
				@endif
				<li class="nav-item nav-item-sidebar permission-STOCK_INVENTORY @if($viewName == 'inventory') active-page @endif"
					data-toggle="tooltip"
					data-placement="right"
					title="{{ $__t('Inventory') }}">
					<a class="nav-link discrete-link"
						href="{{ $U('/inventory') }}">
						<i class="fa-solid fa-fw fa-list"></i>
						<span class="nav-link-text">{{ $__t('Inventory') }}</span>
					</a>
				</li>
				@endif
				@if(VICTUAL_FEATURE_FLAG_CHORES && \Victual\Controllers\Users\User::HasPermissions(\Victual\Controllers\Users\User::PERMISSION_CHORES_VIEW))
				<li class="nav-item nav-item-sidebar permission-CHORE_TRACK_EXECUTION @if($viewName == 'choretracking') active-page @endif"
					data-toggle="tooltip"
					data-placement="right"
					title="{{ $__t('Chore tracking') }}">
					<a class="nav-link discrete-link"
						href="{{ $U('/choretracking') }}">
						<i class="fa-solid fa-fw fa-play"></i>
						<span class="nav-link-text">{{ $__t('Chore tracking') }}</span>
					</a>
				</li>
				@endif
				@if(VICTUAL_FEATURE_FLAG_BATTERIES)
				<li class="nav-item nav-item-sidebar permission-BATTERIES_TRACK_CHARGE_CYCLE @if($viewName == 'batterytracking') active-page @endif"
					data-toggle="tooltip"
					data-placement="right"
					title="{{ $__t('Battery tracking') }}">
					<a class="nav-link discrete-link"
						href="{{ $U('/batterytracking') }}">
						<i class="fa-solid fa-fw fa-car-battery"></i>
						<span class="nav-link-text">{{ $__t('Battery tracking') }}</span>
					</a>
				</li>
				@endif

				@php $firstUserentity = true; @endphp
				@foreach($userentitiesForSidebar as $userentity)
				@if($firstUserentity)
				<div class="nav-item-divider"></div>
				@php $firstUserentity = false; @endphp
				@endif
				<li class="nav-item nav-item-sidebar @if($viewName == 'userobjects' && $__env->yieldContent('title') == $userentity->caption) active-page @endif"
					data-toggle="tooltip"
					data-placement="right"
					title="{{ $userentity->caption }}">
					<a class="nav-link discrete-link"
						href="{{ $U('/userobjects/' . $userentity->name) }}">
						<i class="fa-fw {{ $userentity->icon_css_class }}"></i>
						<span class="nav-link-text">{{ $userentity->caption }}</span>
					</a>
				</li>
				@endforeach

				@php
				$masterDataViews = [
				'products', 'locations', 'shoppinglocations', 'quantityunits',
				'productgroups', 'chores', 'batteries', 'taskcategories',
				'userfields', 'userentities'
				]
				@endphp
				<div class="nav-item-divider"></div>
				<li class="nav-item nav-item-sidebar"
					data-toggle="tooltip"
					data-placement="right"
					title="{{ $__t('Manage master data') }}">
					<a class="nav-link nav-link-collapse discrete-link @if(!in_array($viewName, $masterDataViews)) collapsed @else active-page @endif"
						data-toggle="collapse"
						href="#sub-nav-manage-master-data">
						<i class="fa-solid fa-fw fa-table"></i>
						<span class="nav-link-text">{{ $__t('Manage master data') }}</span>
					</a>
					<ul id="sub-nav-manage-master-data"
						class="sidenav-second-level collapse @if(in_array($viewName, $masterDataViews)) show @endif">
						<li class="@if($viewName == 'products') active-page @endif">
							<a class="nav-link discrete-link"
								href="{{ $U('/products') }}">
								<span class="nav-link-text">{{ $__t('Products') }}</span>
							</a>
						</li>
						@if(VICTUAL_FEATURE_FLAG_STOCK && \Victual\Controllers\Users\User::HasPermissions(\Victual\Controllers\Users\User::PERMISSION_STOCK_VIEW))
						@if(VICTUAL_FEATURE_FLAG_STOCK_LOCATION_TRACKING)
						<li class="@if($viewName == 'locations') active-page @endif">
							<a class="nav-link discrete-link"
								href="{{ $U('/locations') }}">
								<span class="nav-link-text">{{ $__t('Locations') }}</span>
							</a>
						</li>
						@endif
						@if(VICTUAL_FEATURE_FLAG_STOCK_PRICE_TRACKING)
						<li class="@if($viewName == 'shoppinglocations') active-page @endif">
							<a class="nav-link discrete-link"
								href="{{ $U('/shoppinglocations') }}">
								<span class="nav-link-text">{{ $__t('Stores') }}</span>
							</a>
						</li>
						@endif
						@endif
						<li class="@if($viewName == 'quantityunits') active-page @endif">
							<a class="nav-link discrete-link"
								href="{{ $U('/quantityunits') }}">
								<span class="nav-link-text">{{ $__t('Quantity units') }}</span>
							</a>
						</li>
						<li class="@if($viewName == 'productgroups') active-page @endif">
							<a class="nav-link discrete-link"
								href="{{ $U('/productgroups') }}">
								<span class="nav-link-text">{{ $__t('Product groups') }}</span>
							</a>
						</li>
						@if(VICTUAL_FEATURE_FLAG_CHORES && \Victual\Controllers\Users\User::HasPermissions(\Victual\Controllers\Users\User::PERMISSION_CHORES_VIEW))
						<li class="@if($viewName == 'chores') active-page @endif">
							<a class="nav-link discrete-link"
								href="{{ $U('/chores') }}">
								<span class="nav-link-text">{{ $__t('Chores') }}</span>
							</a>
						</li>
						@endif
						@if(VICTUAL_FEATURE_FLAG_BATTERIES)
						<li class="@if($viewName == 'batteries') active-page @endif">
							<a class="nav-link discrete-link"
								href="{{ $U('/batteries') }}">
								<span class="nav-link-text">{{ $__t('Batteries') }}</span>
							</a>
						</li>
						@endif
						@if(VICTUAL_FEATURE_FLAG_TASKS && \Victual\Controllers\Users\User::HasPermissions(\Victual\Controllers\Users\User::PERMISSION_TASKS_VIEW))
						<li class="@if($viewName == 'taskcategories') active-page @endif">
							<a class="nav-link discrete-link"
								href="{{ $U('/taskcategories') }}">
								<span class="nav-link-text">{{ $__t('Task categories') }}</span>
							</a>
						</li>
						@endif
						<li class="@if($viewName == 'userfields') active-page @endif">
							<a class="nav-link discrete-link"
								href="{{ $U('/userfields') }}">
								<span class="nav-link-text">{{ $__t('Userfields') }}</span>
							</a>
						</li>
						<li class="@if($viewName == 'userentities') active-page @endif">
							<a class="nav-link discrete-link"
								href="{{ $U('/userentities') }}">
								<span class="nav-link-text">{{ $__t('Userentities') }}</span>
							</a>
						</li>
					</ul>
				</li>
			</ul>

			<ul class="navbar-nav sidenav-toggler">
				<li class="nav-item">
					<a id="sidenavToggler"
						class="nav-link text-center">
						<i class="fa-solid fa-angle-left"></i>
					</a>
				</li>
			</ul>

			<ul class="navbar-nav ml-auto">
				@if(VICTUAL_AUTHENTICATED && !VICTUAL_IS_EMBEDDED_INSTALL && !VICTUAL_DISABLE_AUTH)
				<li class="nav-item dropdown">
					<a class="nav-link dropdown-toggle discrete-link @if(!empty(VICTUAL_USER_PICTURE_FILE_NAME)) py-0 @endif"
						href="#"
						data-toggle="dropdown">
						@if(empty(VICTUAL_USER_PICTURE_FILE_NAME))
						<i class="fa-solid fa-user"></i>
						@else
						<img class="rounded-circle"
							src="{{ $U('/api/files/userpictures/' . base64_encode(VICTUAL_USER_PICTURE_FILE_NAME) . '?force_serve_as=picture&best_fit_width=32&best_fit_height=32') }}"
							loading="lazy">
						@endif
						{{ VICTUAL_USER_USERNAME }}
					</a>

					<div class="dropdown-menu dropdown-menu-right">
						{{-- A form rather than a link: logging out is a state change, and as a GET
						     it fires from any <img src> a page happens to carry. Sweep finding S8. --}}
						<form method="post"
							action="{{ $U('/logout') }}"
							class="m-0">
							<button type="submit"
								class="dropdown-item logout-button discrete-link"><i class="fa-solid fa-fw fa-sign-out-alt"></i>&nbsp;{{ $__t('Logout') }}</button>
						</form>
						<div class="dropdown-divider"></div>
						@if(!defined('VICTUAL_EXTERNALLY_MANAGED_AUTHENTICATION'))
						<a class="dropdown-item logout-button discrete-link"
							href="{{ $U('/user/' . VICTUAL_USER_ID . '?changepw=true') }}"><i class="fa-solid fa-fw fa-key"></i>&nbsp;{{ $__t('Change password') }}</a>
						@else
						<a class="dropdown-item logout-button discrete-link"
							href="{{ $U('/user/' . VICTUAL_USER_ID) }}"><i class="fa-solid fa-fw fa-key"></i>&nbsp;{{ $__t('Edit user') }}</a>
						@endif
					</div>
				</li>
				@endif

				@if(VICTUAL_AUTHENTICATED)
				<li class="nav-item dropdown">
					<a class="nav-link dropdown-toggle discrete-link"
						href="#"
						data-toggle="dropdown"><i class="fa-solid fa-sliders-h"></i> <span class="d-inline d-lg-none">{{ $__t('View settings') }}</span></a>

					<div class="dropdown-menu dropdown-menu-right">
						<div class="dropdown-item">
							<div class="form-check">
								<input class="form-check-input user-setting-control"
									type="checkbox"
									id="auto-reload-enabled"
									data-setting-key="auto_reload_on_db_change">
								<label class="form-check-label"
									for="auto-reload-enabled">
									{{ $__t('Auto reload on external changes') }}
								</label>
							</div>
						</div>
						<div class="dropdown-item">
							<div class="form-check">
								<input class="form-check-input user-setting-control"
									type="checkbox"
									id="show-clock-in-header"
									data-setting-key="show_clock_in_header">
								<label class="form-check-label"
									for="show-clock-in-header">
									{{ $__t('Show clock in header') }}
								</label>
							</div>
						</div>
						<div class="dropdown-divider"></div>
						<div class="dropdown-item pt-0">
							<div>
								{{ $__t('Night mode') }}
							</div>
							<div class="custom-control custom-radio custom-control-inline">
								<input class="custom-control-input user-setting-control"
									type="radio"
									name="night-mode"
									id="night-mode-on"
									value="on"
									data-setting-key="night_mode">
								<label class="custom-control-label"
									for="night-mode-on">{{ $__t('On') }}</label>
							</div>
							<div class="custom-control custom-radio custom-control-inline">
								<input class="custom-control-input user-setting-control"
									type="radio"
									name="night-mode"
									id="night-mode-follow-system"
									value="follow-system"
									data-setting-key="night_mode">
								<label class="custom-control-label"
									for="night-mode-follow-system">{{ $__t('Use system setting') }}</label>
							</div>
							<div class="custom-control custom-radio custom-control-inline">
								<input class="custom-control-input user-setting-control"
									type="radio"
									name="night-mode"
									id="night-mode-off"
									value="off"
									data-setting-key="night_mode">
								<label class="custom-control-label"
									for="night-mode-off">{{ $__t('Off') }}</label>
							</div>
						</div>
						<div class="dropdown-item">
							<div class="form-check">
								<input class="form-check-input user-setting-control"
									type="checkbox"
									id="auto-night-mode-enabled"
									data-setting-key="auto_night_mode_enabled">
								<label class="form-check-label"
									for="auto-night-mode-enabled">
									{{ $__t('Auto enable in time range') }}
								</label>
							</div>
							<div class="form-inline">
								<input type="text"
									class="form-control my-1 user-setting-control"
									readonly
									id="auto-night-mode-time-range-from"
									placeholder="{{ $__t('From') }} ({{ $__t('in format') }} HH:mm)"
									data-setting-key="auto_night_mode_time_range_from">
								<input type="text"
									class="form-control user-setting-control"
									readonly
									id="auto-night-mode-time-range-to"
									placeholder="{{ $__t('To') }} ({{ $__t('in format') }} HH:mm)"
									data-setting-key="auto_night_mode_time_range_to">
							</div>
							<div class="form-check mt-1">
								<input class="form-check-input user-setting-control"
									type="checkbox"
									id="auto-night-mode-time-range-goes-over-midgnight"
									data-setting-key="auto_night_mode_time_range_goes_over_midnight">
								<label class="form-check-label"
									for="auto-night-mode-time-range-goes-over-midgnight">
									{{ $__t('Time range goes over midnight') }}
								</label>
							</div>
						</div>
						<div class="dropdown-divider"></div>
						<div class="dropdown-item">
							<div class="form-check">
								<input class="form-check-input user-setting-control"
									type="checkbox"
									id="keep_screen_on"
									data-setting-key="keep_screen_on">
								<label class="form-check-label"
									for="keep_screen_on">
									{{ $__t('Keep screen on') }}
								</label>
							</div>
						</div>
						<div class="dropdown-item">
							<div class="form-check">
								<input class="form-check-input user-setting-control"
									type="checkbox"
									id="keep_screen_on_when_fullscreen_card"
									data-setting-key="keep_screen_on_when_fullscreen_card">
								<label class="form-check-label"
									for="keep_screen_on_when_fullscreen_card">
									{{ $__t('Keep screen on while displaying a "fullscreen-card"') }}
								</label>
							</div>
						</div>
					</div>
				</li>
				@endif

				<li class="nav-item dropdown">
					<a class="nav-link dropdown-toggle discrete-link"
						href="#"
						data-toggle="dropdown"><i class="fa-solid fa-wrench"></i> <span class="d-inline d-lg-none">{{ $__t('Settings') }}</span></a>

					<div class="dropdown-menu dropdown-menu-right">
						<a class="dropdown-item discrete-link"
							href="{{ $U('/stocksettings') }}"><i class="fa-solid fa-fw fa-box"></i>&nbsp;{{ $__t('Stock settings') }}</a>
						@if(VICTUAL_FEATURE_FLAG_SHOPPINGLIST && \Victual\Controllers\Users\User::HasPermissions(\Victual\Controllers\Users\User::PERMISSION_SHOPPINGLIST_VIEW))
						<a class="dropdown-item discrete-link permission-SHOPPINGLIST"
							href="{{ $U('/shoppinglistsettings') }}"><i class="fa-solid fa-fw fa-shopping-cart"></i>&nbsp;{{ $__t('Shopping list settings') }}</a>
						@endif
						@if(VICTUAL_FEATURE_FLAG_RECIPES && \Victual\Controllers\Users\User::HasPermissions(\Victual\Controllers\Users\User::PERMISSION_RECIPES_VIEW))
						<a class="dropdown-item discrete-link permission-RECIPES"
							href="{{ $U('/recipessettings') }}"><i class="fa-solid fa-fw fa-pizza-slice"></i>&nbsp;{{ $__t('Recipes settings') }}</a>
						@endif
						@if(VICTUAL_FEATURE_FLAG_CHORES && \Victual\Controllers\Users\User::HasPermissions(\Victual\Controllers\Users\User::PERMISSION_CHORES_VIEW))
						<a class="dropdown-item discrete-link permission-CHORES"
							href="{{ $U('/choressettings') }}"><i class="fa-solid fa-fw fa-home"></i>&nbsp;{{ $__t('Chores settings') }}</a>
						@endif
						@if(VICTUAL_FEATURE_FLAG_TASKS && \Victual\Controllers\Users\User::HasPermissions(\Victual\Controllers\Users\User::PERMISSION_TASKS_VIEW))
						<a class="dropdown-item discrete-link permission-TASKS"
							href="{{ $U('/taskssettings') }}"><i class="fa-solid fa-fw fa-tasks"></i>&nbsp;{{ $__t('Tasks settings') }}</a>
						@endif
						@if(VICTUAL_FEATURE_FLAG_BATTERIES)
						<a class="dropdown-item discrete-link permission-BATTERIES"
							href="{{ $U('/batteriessettings') }}"><i class="fa-solid fa-fw fa-battery-half"></i>&nbsp;{{ $__t('Batteries settings') }}</a>
						@endif
						<div class="dropdown-divider"></div>
						<a data-href="{{ $U('/usersettings') }}"
							class="dropdown-item discrete-link link-return">
							<i class="fa-solid fa-fw fa-user-cog"></i> {{ $__t('User settings') }}
						</a>
						<a class="dropdown-item discrete-link permission-USERS_READ"
							href="{{ $U('/users') }}"><i class="fa-solid fa-fw fa-users"></i>&nbsp;{{ $__t('Manage users') }}</a>
						<div class="dropdown-divider"></div>
						@if(!VICTUAL_DISABLE_AUTH)
						<a class="dropdown-item discrete-link"
							href="{{ $U('/manageapikeys') }}"><i class="fa-solid fa-fw fa-handshake"></i>&nbsp;{{ $__t('Manage API keys') }}</a>
						@endif
						<a class="dropdown-item discrete-link"
							target="_blank"
							href="{{ $U('/api') }}"><i class="fa-solid fa-fw fa-book"></i>&nbsp;{{ $__t('REST API browser') }}</a>
						<a class="dropdown-item discrete-link"
							href="{{ $U('/barcodescannertesting') }}"><i class="fa-solid fa-fw fa-barcode"></i>&nbsp;{{ $__t('Barcode scanner testing') }}</a>
						<div class="dropdown-divider"></div>
						<a class="dropdown-item discrete-link show-as-dialog-link"
							data-dialog-type="wider"
							href="{{ $U('/about?embedded') }}"><i class="fa-solid fa-fw fa-info"></i>&nbsp;{{ $__t('About Victual') }}</a>
					</div>
				</li>
			</ul>
		</div>@endif
	</nav>
	@endif

	<div class="@if(VICTUAL_AUTHENTICATED) content-wrapper @endif pt-0">
		<div class="container-fluid @if(VICTUAL_AUTHENTICATED && !$embedded) pr-1 pl-md-3 pl-2 @endif @if($embedded) px-1 @endif">
			<div class="row mb-3">
				<div id="page-content"
					class="col content-text">
					@yield('content')
				</div>
			</div>
		</div>
	</div>

	<script src="{{ $U('/packages/jquery/dist/jquery.min.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/packages/bootstrap/dist/js/bootstrap.bundle.min.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/packages/bootbox/dist/bootbox.min.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/packages/jquery-serializejson/jquery.serializejson.min.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/packages/moment/min/moment.min.js?v=', true) }}{{ $version }}"></script>
	@if(!empty($__t('moment_locale') && $__t('moment_locale') != 'x'))<script src="{{ $U('/packages', true) }}/moment/locale/{{ $__t('moment_locale') }}.js?v={{ $version }}"></script>@endif
	<script src="{{ $U('/packages/toastr/build/toastr.min.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/packages/sprintf-js/dist/sprintf.min.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/packages/gettext-translator/dist/translator.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/packages/nosleep.js/dist/NoSleep.min.js?v=', true) }}{{ $version }}"></script>

	@if(in_array('bootstrap-combobox', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<script src="{{ $U('/packages/@danielfarrell/bootstrap-combobox/js/bootstrap-combobox.js?v=', true) }}{{ $version }}"></script>
	@endif
	@if(in_array('datatables', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<script src="{{ $U('/packages/datatables.net/js/jquery.dataTables.min.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/packages/datatables.net-bs4/js/dataTables.bootstrap4.min.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/packages/datatables.net-colreorder/js/dataTables.colReorder.min.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/packages/datatables.net-colreorder-bs4/js/colReorder.bootstrap4.min.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/packages/datatables.net-rowgroup/js/dataTables.rowGroup.min.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/packages/datatables.net-rowgroup-bs4/js/rowGroup.bootstrap4.min.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/packages/datatables.net-select/js/dataTables.select.min.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/packages/datatables.net-select-bs4/js/select.bootstrap4.min.js?v=', true) }}{{ $version }}"></script>
	@endif
	@if(in_array('tempusdominus', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<script src="{{ $U('/packages/tempusdominus-bootstrap-4/build/js/tempusdominus-bootstrap-4.min.js?v=', true) }}{{ $version }}"></script>
	@endif
	@if(in_array('summernote', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<script src="{{ $U('/packages/summernote/dist/summernote-bs4.min.js?v=', true) }}{{ $version }}"></script>
	@if(!empty($__t('summernote_locale') && $__t('summernote_locale') != 'x'))<script src="{{ $U('/packages', true) }}/summernote/dist/lang/summernote-{{ $__t('summernote_locale') }}.js?v={{ $version }}"></script>@endif
	@endif
	@if(in_array('bootstrap-select', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<script src="{{ $U('/packages/bootstrap-select/dist/js/bootstrap-select.min.js?v=', true) }}{{ $version }}"></script>
	@if(!empty($__t('bootstrap-select_locale') && $__t('bootstrap-select_locale') != 'x'))<script src="{{ $U('/packages', true) }}/bootstrap-select/dist/js/i18n/defaults-{{ $__t('bootstrap-select_locale') }}.js?v={{ $version }}"></script>@endif
	@endif
	@if(in_array('fullcalendar', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<script src="{{ $U('/packages/fullcalendar/dist/fullcalendar.min.js?v=', true) }}{{ $version }}"></script>
	@if(!empty($__t('fullcalendar_locale') && $__t('fullcalendar_locale') != 'x'))<script src="{{ $U('/packages', true) }}/fullcalendar/dist/locale/{{ $__t('fullcalendar_locale') }}.js?v={{ $version }}"></script>@endif
	@endif
	@if(in_array('daterangepicker', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<script src="{{ $U('/packages/daterangepicker/daterangepicker.js?v=', true) }}{{ $version }}"></script>
	@endif
	@if(in_array('zxing', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<script src="{{ $U('/packages/@zxing/library/umd/index.min.js?v=', true) }}{{ $version }}"></script>
	@endif
	@if(in_array('bwipjs', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<script src="{{ $U('/packages/bwip-js/dist/bwip-js-min.js?v=', true) }}{{ $version }}"></script>
	@endif
	@if(in_array('chartjs', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<script src="{{ $U('/packages/chart.js/dist/Chart.min.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/packages/chartjs-plugin-colorschemes/dist/chartjs-plugin-colorschemes.min.js?v=', true) }}{{ $version}}"></script>
	<script src="{{ $U('/packages/chartjs-plugin-doughnutlabel/dist/chartjs-plugin-doughnutlabel.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/packages/chartjs-plugin-piechart-outlabels/dist/chartjs-plugin-piechart-outlabels.min.js?v=', true) }}{{ $version}}"></script>
	<script src="{{ $U('/packages/chartjs-plugin-trendline/dist/chartjs-plugin-trendline.min.js?v=', true) }}{{ $version}}"></script>
	@endif

	<script src="{{ $U('/js/extensions.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/js/victual_menu_layout.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/js/victual.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/js/victual_entity.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/js/victual_stock_dialogs.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/js/victual_dbchangedhandling.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/js/victual_wakelockhandling.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/js/victual_nightmode.js?v=', true) }}{{ $version }}"></script>
	<script src="{{ $U('/js/victual_clock.js?v=', true) }}{{ $version }}"></script>

	@if(in_array('datatables', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<script src="{{ $U('/js/victual_datatables.js?v=', true) }}{{ $version }}"></script>
	@endif
	@if(in_array('summernote', $VICTUAL_REQUIRED_FRONTEND_PACKAGES))
	<script src="{{ $U('/js/victual_summernote.js?v=', true) }}{{ $version }}"></script>
	@endif

	@stack('pageScripts')
	@stack('componentScripts')
	<script src="{{ $U('/viewjs/' . $viewName . '.js?v=', true) }}{{ $version }}"></script>

	@if(file_exists(VICTUAL_DATAPATH . '/custom_js.html'))
	@php include VICTUAL_DATAPATH . '/custom_js.html' @endphp
	@endif
</body>

</html>
