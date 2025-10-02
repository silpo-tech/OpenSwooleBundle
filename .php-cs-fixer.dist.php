<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->exclude('vendor')
    ->notPath('DependencyInjection/Configuration.php')
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'concat_space' => ['spacing' => 'one'],
        'nullable_type_declaration' => ['syntax' => 'union'],
        'yoda_style' => false,
        'global_namespace_import' => false,
        'multiline_whitespace_before_semicolons' => ['strategy' => 'new_line_for_chained_calls'],
        'no_multiline_whitespace_around_double_arrow' => false,
        'ternary_to_null_coalescing' => true,
        'phpdoc_align' => ['align' => 'left'],
        'method_chaining_indentation' => true,
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'array_indentation' => true,
        'declare_strict_types' => true,
        'static_lambda' => true,
        'use_arrow_functions' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'match', 'parameters']],
        'native_constant_invocation' => false,
        'native_function_invocation' => false,
        'types_spaces' => ['space_multiple_catch' => 'single'],
        'single_line_throw' => false,
    ])
    ->setFinder($finder)
;
