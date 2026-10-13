<?php

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR2' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => true,
    ])
    ->setFinder(PhpCsFixer\Finder::create()->in([
        __DIR__.'/src',
        __DIR__.'/assets/config',
        __DIR__.'/tests',
        __DIR__.'/.github/scripts',
    ]));
