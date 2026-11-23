<?php

declare(strict_types=1);

/**
 * Bootstrap of the unit suite: Composer's autoloader, then the few WordPress classes the tests build.
 *
 * The classes are defined here and not in Pest.php: Pest loads Pest.php in every run, the integration suite included,
 * and that suite loads a real WordPress, which declares the same classes.
 */
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once __DIR__.'/Fixtures/wordpress.php';
