# Tips

Small conventions that apply across the application rather than to one household task.

## Date field shorthands

Every date and time field uses ISO-8601 regardless of localization, and accepts these
shorthands as you type:

| Type | Example | Result |
|---|---|---|
| `MMDD` | `0517` | That day this year if it is still ahead, otherwise next year |
| `YYYYMMDD` | `20260417` | `2026-04-17` |
| `YYYYMMe` or `YYYYMM+` | `202607e` | The end of that month — `2026-07-31` |
| `[+/-]n[d/m/y]` | `+1m` | Relative to today — the same day next month |
| `x` | `x` | `2999-12-31`, the alias for "never overdue" |

Down/up arrows change a date by 1 day, right/left by 1 week; with Shift, by 1 month and 1
year respectively.

## Keyboard shortcuts for buttons

Wherever a button contains a bold, highlighted letter, that letter is its shortcut key.
Button "**P** Add as new product" can be pressed with the `P` key.

## Installing Victual as an app

The web frontend is a responsive, installable web app
([PWA](https://en.wikipedia.org/wiki/Progressive_web_app)) with no offline capability — it
still needs to reach the server for every request, but installing it gives a native-feeling
icon and window rather than a browser tab. `/manifest` serves the manifest a browser reads
to offer that install prompt.
