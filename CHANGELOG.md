# Changelog

All notable changes to `pollora/block-bindings` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The Block Bindings classes of the Pollora framework as a package of their own, class names unchanged: `BindingResolver`, `BindingSourceBuilder`, `BindingSourceRegistry`, `BindingContext`, the four sources Pollora ships (`PostMetaSource`, `TermMetaSource`, `AuthorMetaSource`, `OptionSource`), `BlockBindingDiscovery`, the WordPress adapters, the editor data and script (`resources/js/block-bindings.js`) and the preview route `ResolveBindingsController`
- Four contracts for what binds them to an environment: `MethodInvokerInterface` (the parameters of a source's method), `BindingEventsInterface` (`BindingResolved`), `PostFinderInterface` (`BindingContext::post()`), alongside `ContentVisibilityInterface` and `ValuePresenterInterface`. `ReflectionMethodInvoker` is the default invoker; the framework implements the others with Laravel
- It runs without Laravel: its classes reach for nothing in Illuminate, which an architecture test keeps. The sources are made by any PSR-11 container
