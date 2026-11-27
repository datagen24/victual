@extends('layout.default')

@section('title', $__t('About Victual'))

@section('content')
<div class="row">
	<div class="col text-center">
		<h2 class="title">@yield('title')</h2>

		<hr class="my-2">

		<ul class="nav nav-tabs victual-tabs justify-content-center mt-3">
			<li class="nav-item">
				<a class="nav-link discrete-link active"
					id="system-info-tab"
					data-toggle="tab"
					href="#system-info">{{ $__t('System info') }}</a>
			</li>
			<li class="nav-item">
				<a class="nav-link discrete-link"
					id="changelog-tab"
					data-toggle="tab"
					href="#changelog">{{ $__t('Changelog') }}</a>
			</li>
		</ul>

		<div class="tab-content victual-tabs mt-3">

			<div class="tab-pane show active"
				id="system-info">
				<div class="row">
					<div class="col-auto">
						{{-- The PHP, database engine and OS rows are absent for a user without ADMIN,
							because ApplicationService::GetSystemInfo() leaves those keys out for them. --}}
						<table class="table table-borderless table-responsive table-sm text-left">
							<tr>
								<td class="text-right">Version</td>
								<td><code>{{ $versionInfo->Version }}</code></td>
							</tr>
							<tr>
								<td class="text-right">Released on</td>
								<td><code>{{ $versionInfo->ReleaseDate }}</code> <time class="timeago timeago-contextual text-muted"
										datetime="{{ $versionInfo->ReleaseDate }}"></time></td>
							</tr>
							@isset($systemInfo['php_version'])
							<tr>
								<td class="text-right">PHP Version</td>
								<td><code>{{ $systemInfo['php_version'] }}</code></td>
							</tr>
							@endisset
							@isset($systemInfo['database_engine'])
							<tr>
								<td class="text-right">Database Engine</td>
								<td><code>{{ $systemInfo['database_engine'] }}</code></td>
							</tr>
							@endisset
							<tr>
								<td class="text-right">Database Version</td>
								<td><code>{{ $systemInfo['db_version'] }}</code></td>
							</tr>
							@isset($systemInfo['os'])
							<tr>
								<td class="text-right">OS</td>
								<td><code>{{ $systemInfo['os'] }}</code></td>
							</tr>
							@endisset
							<tr>
								<td class="text-right">Client</td>
								<td><code>{{ $systemInfo['client'] }}</code></td>
							</tr>
						</table>
					</div>
				</div>
			</div>

			<div class="tab-pane show"
				id="changelog">
				@php $Parsedown = new Parsedown(); @endphp
				@foreach($changelog['changelog_items'] as $changelogItem)
				<div class="card my-2">
					<div class="card-header">
						<a class="discrete-link"
							data-toggle="collapse-next"
							href="#">
							Version <span class="font-weight-bold">{{ $changelogItem['version'] }}</span><br>
							Released on <span class="font-weight-bold">{{ $changelogItem['release_date'] }}</span>
							<time class="timeago timeago-contextual text-muted"
								datetime="{{ $changelogItem['release_date'] }}"></time>
						</a>
					</div>
					<div class="collapse @if($changelogItem['release_number'] >= $changelog['newest_release_number'] - 4) show @endif">
						<div class="card-body text-left">
							{!! $Parsedown->text($changelogItem['body']) !!}
						</div>
					</div>
				</div>
				@endforeach
			</div>

		</div>

		<p class="small text-muted border-top pt-3">
			Victual is a hard fork of
			<a href="https://github.com/grocy/grocy"
				class="text-dark"
				target="_blank">grocy</a>, a hobby project by
			<a href="https://berrnd.de"
				class="text-dark"
				target="_blank">Bernd Bestel</a>, created with passion since 2017.<br>
			Code originating from grocy is MIT &copy; Bernd Bestel; this fork's changes are
			BSD 3-Clause. The fork has deliberately diverged and no longer syncs with
			upstream in either direction.<br>
			If grocy is useful to you,
			<a href="https://grocy.info/#say-thanks"
				class="text-dark"
				target="_blank">say thanks to Bernd</a> <i class="fa-solid fa-heart"></i><br>
			Life runs on Code<br>
		</p>
	</div>
</div>
@stop
