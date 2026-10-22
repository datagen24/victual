# Starter fonts

Bundled so a fresh installation can add text to a label without uploading a font first
(issue #1033, split from #948). They are stored as label assets only when an admin presses
**Add starter fonts** in the template editor; nothing is seeded by a migration.

| File | Family | SHA-256 |
|---|---|---|
| `NotoSans-Regular.ttf` | Noto Sans Regular | `478c558ea716033cd60c03438f628dfa75694dcf6b5f6d505a2f05fd2b4f3823` |
| `NotoSans-Bold.ttf` | Noto Sans Bold | `1df075a380fc7cb898acf64c1f7b3b4dd780de3caa860178bf929de35817a913` |
| `NotoSansMono-Regular.ttf` | Noto Sans Mono Regular | `65b5e2b2c4a1fba9ae8be1f026cb35b03dcb8886d9b2a4147054fde12f7e767d` |

Source: the hinted static TTFs in `fonts/NotoSans/hinted/ttf/` and
`fonts/NotoSansMono/hinted/ttf/` of
[notofonts/notofonts.github.io](https://github.com/notofonts/notofonts.github.io) at commit
`957448b6168f09dca2c8005449e9b6f3fd2dd207`. Licence: SIL Open Font License 1.1, in
[OFL.txt](OFL.txt), from notofonts/latin-greek-cyrillic at tag `NotoSans-v2.015`.

`LabelAssetService::STARTER_FONTS` carries the same digests and refuses a file that does
not match, so replacing a font here means updating both.
