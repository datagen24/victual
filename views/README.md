# Views

Blade templates for the server-rendered pages, and the scripts in `public/` that run on
them. `Victual\Helpers\SlimBladeView` renders the templates, and controllers call it through
`BaseController::Render()` and `BaseController::RenderPage()`. A view name is the file name
without `.blade.php`, in Blade dot notation for subdirectories (`components.numberpicker`).

## Contents

| Path | Contents |
|---|---|
| `*.blade.php` | One template per page, such as `stockoverview`, `productform`, or `mealplan`. |
| `layout/default.blade.php` | The page shell: head, navigation, and script loading. Page templates `@extends('layout.default')`. |
| `components/` | Partial templates included with `@include('components.<name>', [...])`: pickers, cards, form fragments. |
| `errors/` | Error pages. `base.blade.php` is their layout; `403`, `404`, `4xx`, and `500` extend it. |

## Relation to public/

The layout sets `Victual.View` to the rendered view name and loads
`public/viewjs/<viewName>.js` after the shared scripts. A page's client-side behaviour is
therefore in the file with the same name under `public/viewjs/`. A component that has a
script, such as `productpicker`, pushes `public/viewjs/components/<name>.js` onto the
`componentScripts` stack. A page that needs an optional third-party package declares it on
its first line, for example `@php require_frontend_packages(['bootstrap-select']); @endphp`.

## The public/ web root

The web server serves `public/`. Every request that does not match a file there goes to
`public/index.php` (see `public/.htaccess`), which loads [app.php](../app.php) and the routes
in [routes.php](../routes.php).

| Path | Contents |
|---|---|
| `index.php` | The front controller. It runs `helpers/PrerequisiteChecker.php`, then `app.php`. |
| `js/` | Scripts that `views/layout/default.blade.php` loads on every page. |
| `viewjs/` | One script per view, named after the view. |
| `viewjs/components/` | Scripts for the Blade components in `views/components/`. |
| `css/` | The application stylesheet, the menu layout, and night mode. |
| `img/`, `uisounds/` | Icons, logos, and the scanner and notification sounds. |
| `packages/` | Third-party frontend packages. Not tracked; see [Third-party packages](#third-party-packages). |

`public/` has no README because any file there is served as-is: the web server returns an
existing file without reaching `index.php`, and the Nix image copies the whole directory into
the web root (`nix/webroot.nix`). Keep documentation for it in this file.

### Load order

`views/layout/default.blade.php` loads scripts in this order:

1. Third-party packages from `public/packages/`.
2. Shared scripts from `public/js/`. `extensions.js` comes first, then `victual.js` and
   `victual_entity.js`. `victual_entity.js` provides `Victual.EntityList` and the other list
   and form factories that the master data pages use.
3. The `pageScripts` and `componentScripts` Blade stacks.
4. `public/viewjs/<viewName>.js`, where `viewName` is the view that the controller rendered.

`js/victual_datatables.js` and `js/victual_summernote.js` load only when a view calls
`require_frontend_packages()` (in `helpers/extensions.php`) for `datatables` or
`summernote`. There is no bundler, so a shared function is another `<script>` tag.
[Plan 12](../docs/plans/landed/12-frontend-shared-core.md) describes the shared core.

### Third-party packages

`public/packages/` is populated by Yarn, using the dependencies in
[package.json](../package.json) and the `--modules-folder public/packages` setting in
`.yarnrc`. The directory is listed in `.gitignore`.

### Frontend sink rules

[AGENTS.md](../AGENTS.md#ground-rules) sets two rules for code in `public/js/` and
`public/viewjs/`:

- A string read from the DOM reaches jQuery through `$(document).find(sel)`, never `$(sel)`.
- Markup is built as nodes, for example `$("<option>").text(value)`. A value is never
  concatenated into a string passed to `.html()` or `.append()`.

The `frontend-security` CI job checks both rules with
[`.devtools/frontend/s29-payload.js`](../.devtools/frontend/s29-payload.js).
[Plan 21](../docs/plans/landed/21-frontend-sink-discipline.md) explains the rules. The
Playwright probes in [.devtools/frontend/](../.devtools/frontend/README.md) exercise the
pages in a browser.

## Related reading

- [AGENTS.md](../AGENTS.md): where routes, controllers, and services live.
- [Plan 12](../docs/plans/landed/12-frontend-shared-core.md): the shared list and form
  factories that the master data pages use.
