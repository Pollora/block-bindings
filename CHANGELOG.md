# Changelog

All notable changes to `pollora/block-bindings` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-10-28

The package now requires Colt 11: update `pollora/colt` to `^11.0` (PHP 8.3 or later, which this package already needed).

### Changed

- **Requires `pollora/colt` `^11.0`** (it was `^10.0.1`). Colt 11 needs PHP 8.3, which this package already required

## [0.1.0] - 2026-10-15

### Added

- The Block Bindings classes of the Pollora framework as a package of their own, class names unchanged: `BindingResolver`, `BindingSourceBuilder`, `BindingSourceRegistry`, `BindingContext`, the four sources Pollora ships (`PostMetaSource`, `TermMetaSource`, `AuthorMetaSource`, `OptionSource`), `BlockBindingDiscovery`, the WordPress adapters, the editor data and script (`resources/js/block-bindings.js`) and the preview route `ResolveBindingsController`
- Four contracts for what binds them to an environment: `MethodInvokerInterface` (the parameters of a source's method), `BindingEventsInterface` (`BindingResolved`), `PostFinderInterface` (`BindingContext::post()`), alongside `ContentVisibilityInterface` and `ValuePresenterInterface`. `ReflectionMethodInvoker` is the default invoker; the framework implements the others with Laravel
- It runs without Laravel: its classes reach for nothing in Illuminate, which an architecture test keeps. The sources are made by any PSR-11 container
