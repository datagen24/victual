# Victual vocabulary for OpenSTE

[.vale-ste.yml](../../.vale-ste.yml) adds Victual technical names to
OpenSTE's built-in software vocabulary. It works with `stuffbucket/vale` v0.15.0.
The repository's `.vale.ini`, commit checks, and CI use `vale-cli/vale` independently.

## Use locally

Start the installed OpenSTE binary from the repository root:

```sh
openste-vale lint --audit docs/manual/operator/label-printing.md
openste-vale mcp
```

OpenSTE finds `.vale-ste.yml` by searching upward from its working directory.
User settings and project vocabulary combine. An explicit personal `--config` also
combines with the project vocabulary. Start the MCP server in this checkout or a
subdirectory. The `lint_text` filename selects the markup format; it does not select
a project's configuration. Restart an existing MCP process after editing this file.

When the server must start elsewhere, pass the project file explicitly:

```sh
openste-vale mcp --config /absolute/path/to/victual/.vale-ste.yml
```

User settings still load in this form. Do not copy project terms into a global learned
vocabulary: they would then apply to other projects. Another project can use this
file as an explicit configuration layer after reviewing its terms.

## Maintain the terms

Add a term only when it names a project concept or technical operation. Record its
meaning in a nearby comment or this document. Include required plural forms; OpenSTE
matches case without requiring capitalized copies.

`state` and `view` are exceptions for database and application concepts. `label`,
`product`, and `form` name inventory entities and interface controls. `true` and `false`
name boolean values. Deployment terms name software components. Human review must
check other meanings of these single-word exceptions.

Keep ordinary words such as `may`, `should`, `every`, and `exactly` out of the technical
list. Review their suggestions for changes to permission, obligation, or precision.
Do not add filler words to reduce a finding count.

In v0.15.0, adding a new phrase such as `timer running` does not exempt its component
words from vocabulary findings. This was tested with the installed release. Use
single-word technical entries only. Leave ambiguous words such as `running`, `base`,
and `harness` for review instead of approving all their meanings.

Nominalization checks remain independent of this vocabulary. For example, approving
`deployment` does not remove its nominalization finding. The file does not disable
checks or establish ASD-STE100 compliance.

## Verify

Run the integration tests against the installed binary:

```sh
OPENSTE="$(command -v openste-vale)" python3 -m unittest discover -s .devtools/openste
```

The tests use temporary directories and isolated user settings. They check project
configuration discovery, retained ambiguous terms, retained filler findings, and MCP output.
They do not install a binary or change a personal vocabulary store.
