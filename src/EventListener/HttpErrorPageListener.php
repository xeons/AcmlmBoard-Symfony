<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Twig\Environment;

/**
 * Renders the board's own error pages for expected HTTP failures while debugging.
 *
 * With debug on, Symfony hands every exception to its stack-trace page, so a
 * mistyped thread id shows a wall of framework internals instead of the "Not
 * found" page the board already has. Those templates then only ever get exercised
 * in production - which is the worst place to discover one of them is broken.
 *
 * The split is by status code, not by convenience. Anything below 500 is an
 * expected answer to a bad request: the row is gone, the member lacks the power
 * level, the method is wrong. Those get the board's page. A 500 is a fault in the
 * board itself, and the trace is the entire point of debug mode, so it is left
 * alone. Append ?_trace=1 to any URL to force the trace for a 4xx too.
 *
 * Does nothing when debug is off, where TwigErrorRenderer already does this.
 */
#[AsEventListener(event: ExceptionEvent::class, priority: 0)]
final class HttpErrorPageListener
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$this->debug || $event->hasResponse()) {
            return;
        }

        $throwable = $event->getThrowable();
        if (!$throwable instanceof HttpExceptionInterface) {
            return;
        }

        $status = $throwable->getStatusCode();
        if ($status >= 500) {
            return;
        }

        $request = $event->getRequest();

        // The profiler toolbar, the feeds and anything asking for JSON all come
        // through here; only a page a person is reading wants a page back.
        if ('html' !== $request->getRequestFormat()) {
            return;
        }

        if ($request->query->has('_trace')) {
            return;
        }

        $template = $this->twig->getLoader()->exists($specific = \sprintf('bundles/TwigBundle/Exception/error%d.html.twig', $status))
            ? $specific
            : 'bundles/TwigBundle/Exception/error.html.twig';

        try {
            $html = $this->twig->render($template, [
                'status_code' => $status,
                'status_text' => Response::$statusTexts[$status] ?? '',
                'exception' => $throwable,
            ]);
        } catch (\Throwable) {
            // A broken error template must not replace the original failure with
            // its own; let Symfony's renderer have it.
            return;
        }

        $event->setResponse(new Response($html, $status, $throwable->getHeaders()));
    }
}
