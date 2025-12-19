<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__.'/src')
    ->in(__DIR__.'/tests')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unreachable_default_argument_value' => false,
        'braces_position' => ['allow_single_line_empty_anonymous_classes' => true],
        'heredoc_to_nowdoc' => false,
        'phpdoc_annotation_without_dot' => false,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
;
