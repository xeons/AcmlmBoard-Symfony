<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\SearchType;
use App\Repository\BoardConfigRepository;
use App\Repository\ForumRepository;
use App\Repository\PostRepository;
use App\Service\PostRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Post search.
 *
 * Two things the original got wrong, both fixed structurally rather than by
 * remembering to be careful:
 *
 *   - the WHERE clause was assembled by string concatenation from `$qmsg`, `$qip`,
 *     `$quser`, `$d1m`/`$d1d`/`$d1y` and `$pord`, several with no escaping at all.
 *     Everything is a bound parameter now.
 *   - results were not filtered by forum visibility unless the user happened to
 *     select a forum, so search returned the contents of restricted forums to
 *     anyone allowed to search at all. Results are always scoped to what the viewer
 *     can read.
 */
final class SearchController extends AbstractBoardController
{
    #[Route('/search', name: 'app_search', methods: ['GET'])]
    public function search(
        Request $request,
        PostRepository $posts,
        ForumRepository $forums,
        BoardConfigRepository $configs,
        PostRenderer $renderer,
    ): Response {
        $minPower = $configs->get()->getSearchMinPower();

        if ($this->viewerPower() < $minPower) {
            return $this->render('search/denied.html.twig');
        }

        $form = $this->createForm(SearchType::class, null, [
            'forums' => $forums->findForJumpMenu($this->viewerPower()),
            'method' => 'GET',
            'csrf_protection' => false,
        ]);
        $form->handleRequest($request);

        $results = null;
        $total = 0;
        $page = $this->pageFrom($request);
        $perPage = $this->perPage($request, $this->boardUser()?->getPostsPerPage() ?? 20);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $hasCriteria = ('' !== trim((string) ($data['text'] ?? '')))
                || null !== ($data['author'] ?? null)
                || ('' !== trim((string) ($data['ip'] ?? '')));

            if (!$hasCriteria) {
                $this->addFlash('error', 'Enter some text, an author or an IP to search for.');
            } else {
                // Only admins may search by IP.
                $ip = $this->isGranted('ROLE_ADMIN') ? ($data['ip'] ?? null) : null;

                $paginator = $posts->search(
                    visibleForumIds: $forums->findReadableIds($this->viewerPower()),
                    text: $data['text'] ?? null,
                    author: $data['author'] ?? null,
                    ip: $ip,
                    forum: $data['forum'] ?? null,
                    after: $data['after'] ?? null,
                    before: $data['before'] ?? null,
                    newestFirst: 'oldest' !== ($data['order'] ?? 'newest'),
                    page: $page,
                    perPage: $perPage,
                );

                $total = \count($paginator);
                $results = $renderer->renderAll(iterator_to_array($paginator), $this->boardUser());
            }
        }

        return $this->render('search/search.html.twig', [
            'form' => $form,
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max(1, (int) ceil($total / $perPage)),
        ]);
    }
}
