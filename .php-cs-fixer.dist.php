<?php
declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()->in(__DIR__ . '/vereinsflieger_login')->name('*.php');
return (new PhpCsFixer\Config())
    ->setRules(['@PSR12' => true, 'no_unused_imports' => true, 'ordered_imports' => ['sort_algorithm' => 'alpha']])
    ->setFinder($finder)
    ->setUsingCache(false);
