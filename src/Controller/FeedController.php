<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Forum;
use App\Entity\Thread;
use App\Repository\PostRepository;
use App\Repository\ThreadRepository;
use App\Security\Voter\ForumVoter;
use App\Security\Voter\ThreadVoter;
use App\Service\MarkupRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Atom feeds for forums and threads.
 *
 * The original's rss-forum.php and rss-thread.php emitted post bodies verbatim into
 * the XML - raw HTML, unescaped, straight from the database - so any post containing
 * a "<" produced a malformed document, and a feed reader that rendered the HTML
 * inherited every XSS the board had. Bodies are rendered through the sanitizer and
 * then XML-escaped by Twig's autoescaping.
 */
final class FeedController extends AbstractBoardController
{
    #[Route('/forum/{id<\d+>}/feed.atom', name: 'app_feed_forum', methods: ['GET'])]
    public function forum(Forum $forum, ThreadRepository $threads, MarkupRenderer $markup): Response
    {
        $this->denyAccessUnlessGranted(ForumVoter::VIEW, $forum);

        $items = $threads->findLatestForFeed($forum, 25);

        $response = $this->render('feed/forum.atom.twig', [
            'forum' => $forum,
            'threads' => $items,
            // A forum with no threads still has to produce a valid Atom document,
            // and <updated> is required whether or not there are entries.
            'updatedAt' => ($items[0] ?? null)?->getLastPostAt() ?? new \DateTimeImmutable(),
            'markup' => $markup,
        ]);

        $response->headers->set('Content-Type', 'application/atom+xml; charset=UTF-8');

        return $response;
    }

    #[Route('/thread/{id<\d+>}/feed.atom', name: 'app_feed_thread', methods: ['GET'])]
    public function thread(Thread $thread, PostRepository $posts, MarkupRenderer $markup): Response
    {
        $this->denyAccessUnlessGranted(ThreadVoter::VIEW, $thread);

        $items = $posts->findLatestForFeed($thread, 25);

        $response = $this->render('feed/thread.atom.twig', [
            'thread' => $thread,
            'posts' => $items,
            'updatedAt' => $thread->getLastPostAt(),
            'markup' => $markup,
        ]);

        $response->headers->set('Content-Type', 'application/atom+xml; charset=UTF-8');

        return $response;
    }
}
