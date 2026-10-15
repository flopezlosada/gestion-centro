<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\SubmissionOnce;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * {@code {{ submission_once_field() }}} inside a form that creates something: the hidden field with a fresh
 * one-use token, checked by {@see SubmissionOnce::claim()}.
 */
class SubmissionOnceExtension extends AbstractExtension
{
    /**
     * @param SubmissionOnce $once the token issuer
     */
    public function __construct(private readonly SubmissionOnce $once)
    {
    }

    /**
     * @return list<TwigFunction> the submission_once_field() function
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('submission_once_field', $this->field(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * The hidden input with a fresh token.
     *
     * @return string the HTML of the field
     */
    public function field(): string
    {
        return sprintf('<input type="hidden" name="%s" value="%s">', SubmissionOnce::FIELD, $this->once->token());
    }
}
