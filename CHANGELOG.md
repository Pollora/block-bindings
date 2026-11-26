# Changelog

All notable changes to `pollora/block-bindings` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Without a container, the default invoker now builds a class-typed parameter whose class needs no argument, as documented; it failed with "Unable to resolve the parameter"

## [0.2.2] - 2026-11-23

### Added

- An integration suite (`tests/Integration`, `composer test:integration`) that runs against a real WordPress: registration of a source with the Block Bindings API and the rendering of a bound paragraph through a real `WP_Block`, the visibility of a draft, the preview route's validation and capability checks, and the editor script's handles and inline data. It is not part of the default `pest` run; the harness's optional `WordPress integration` job starts it.

### Changed

- The unit suite loads the few WordPress classes its tests build from `tests/bootstrap.php` instead of `tests/Pest.php`, which the integration suite also loads (the real WordPress declares the same classes). No change to the package.
- Accepts `pollora/discovery` ^0.3 (`^0.1 || ^0.2 || ^0.3 || ^1.0`)

## [0.2.1] - 2026-11-19

### Changed

- Accepts `pollora/discovery` 0.2 (`^0.1 || ^0.2 || ^1.0`)
- The `BindingResolver` builds its default invoker from the new application-layer `DefaultMethodInvoker` instead of the infrastructure `ReflectionMethodInvoker`, so that the application layer no longer depends on the infrastructure layer. `ReflectionMethodInvoker` keeps its name, namespace and behavior: it delegates to `DefaultMethodInvoker`

## [0.2.0] - 2026-10-28

The package now requires Colt 11: update `pollora/colt` to `^11.0` (PHP 8.3 or later, which this package already needed).

### Changed

- **Requires `pollora/colt` `^11.0`** (it was `^10.0.1`). Colt 11 needs PHP 8.3, which this package already required

## [0.1.0] - 2026-10-15

### Added

- The Block Bindings classes of the Pollora framework as a package of their own, class names unchanged: `BindingResolver`, `BindingSourceBuilder`, `BindingSourceRegistry`, `BindingContext`, the four sources Pollora ships (`PostMetaSource`, `TermMetaSource`, `AuthorMetaSource`, `OptionSource`), `BlockBindingDiscovery`, the WordPress adapters, the editor data and script (`resources/js/block-bindings.js`) and the preview route `ResolveBindingsController`
- Four contracts for what binds them to an environment: `MethodInvokerInterface` (the parameters of a source's method), `BindingEventsInterface` (`BindingResolved`), `PostFinderInterface` (`BindingContext::post()`), alongside `ContentVisibilityInterface` and `ValuePresenterInterface`. `ReflectionMethodInvoker` is the default invoker; the framework implements the others with Laravel
- It runs without Laravel: its classes reach for nothing in Illuminate, which an architecture test keeps. The sources are made by any PSR-11 container
