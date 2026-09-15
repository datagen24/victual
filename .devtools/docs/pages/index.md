<img class="wordmark light" src="assets/logo.svg" alt="Victual">
<img class="wordmark dark" src="assets/logo-dark.svg" alt="Victual">

# Victual

Victual is a self-hosted groceries and household management application, maintained by
Steven Peterson. It is a hard fork of [grocy](https://github.com/grocy/grocy), created by
Bernd Bestel, and retains grocy's stock, shopping list, recipe, meal planning and chore
features.

## Looking for installation instructions?

Start with the [Manual](manual/index.md), specifically
[Getting started](manual/getting-started.md): installing from a checkout and from the
container images, PostgreSQL setup, and the first login. From there, the Manual also covers
[configuration](manual/configuration.md), day-to-day use of every part of the application,
and operator topics like the REST API, backup and restore, and updating.

One thing to know before you follow a guide written for upstream grocy: **most of them do not
apply here.** Victual runs on PostgreSQL alone, ships images built by Nix from `scratch`, and
runs without a persistent application volume. A community article about installing grocy with
a SQLite file behind nginx describes a system this one is not compatible with.

## Development

[The developer documentation](development/index.md) is for someone changing Victual rather
than running it: the constitution and contribution conventions, the data model with its
schema diagrams, the PostgreSQL schema and its porting rules, the build and deployment
layout, and the architecture decision records.

Research plans are deliberately not published here. They record work in progress, with
delivery status as of a date and questions that may still be unanswered, and they live in
[docs/plans/](https://github.com/datagen24/victual/tree/master/docs/plans) in the repository.
[ADR-0020](development/adr/0020-documentation-publication-boundary.md) explains that boundary.
