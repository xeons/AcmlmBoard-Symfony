<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\BoardContext;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes the per-request board context to every template as `board`, so the base
 * layout does not need each controller to pass the same eight values.
 *
 * BoardContext resolves lazily - a template that never touches board.stats never
 * triggers the query.
 */
final class BoardContextExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private readonly BoardContext $context)
    {
    }

    public function getGlobals(): array
    {
        return ['board' => $this->context];
    }
}
