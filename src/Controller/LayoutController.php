<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\EditLayoutType;
use App\Repository\UserPictureCategoryRepository;
use App\Service\PostRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The post-layout editor: header, signature and post background.
 *
 * This is the board's marquee feature and its historical security hole. The raw
 * text is stored exactly as typed; the sanitizer runs on render, so a user can
 * always get back in and repair a layout the allowlist rejects, rather than finding
 * their input silently mangled at save time.
 */
final class LayoutController extends AbstractBoardController
{
    #[Route('/layout', name: 'app_layout_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        EntityManagerInterface $em,
        PostRenderer $renderer,
    ): Response {
        $user = $this->requireBoardUser();

        $form = $this->createForm(EditLayoutType::class, $user);
        $form->handleRequest($request);

        // Live preview: the same renderer the thread page uses, so what appears here
        // is exactly what other members will see, sanitizer included.
        $preview = null;
        if ($form->isSubmitted() && $form->isValid()) {
            $preview = $renderer->renderPreview(
                $user,
                'This is what a post of yours will look like.',
                $user,
            );

            if ($form->get('save')->isClicked()) {
                $em->flush();
                $this->addFlash('success', 'Layout saved.');

                return $this->redirectToRoute('app_layout_edit');
            }

            // Preview only: discard the in-memory changes so a preview never writes.
            $em->refresh($user);
        }

        return $this->render('layout/edit.html.twig', [
            'form' => $form,
            'preview' => $preview,
        ]);
    }

    /** The curated avatar gallery. */
    #[Route('/avatars', name: 'app_avatars', methods: ['GET'])]
    public function avatars(Request $request, UserPictureCategoryRepository $categories): Response
    {
        $pages = $categories->findPageNumbers();

        // Default to the first page that exists, not to zero. The gallery's pages are
        // numbered by whoever curated it - the original's ran 1 to 5 - so defaulting
        // to 0 asked for a page nothing is on and rendered an empty gallery, which
        // looks exactly like having no avatars at all.
        $page = $request->query->getInt('gallery', 0);
        if (!\in_array($page, $pages, true)) {
            $page = $pages[0] ?? 0;
        }

        return $this->render('layout/avatars.html.twig', [
            'categories' => $categories->findWithPictures($page),
            'pages' => $pages,
            'currentPage' => $page,
        ]);
    }

    /** Sets the current user's picture from the gallery. */
    #[Route('/avatars/choose', name: 'app_avatar_choose', methods: ['POST'])]
    public function chooseAvatar(
        Request $request,
        \App\Repository\UserPictureRepository $pictures,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->requireBoardUser();
        $this->assertCsrf($request, 'choose-avatar');

        $picture = $pictures->find($request->request->getInt('picture'));
        if (null === $picture) {
            $this->addFlash('error', 'That avatar does not exist.');

            return $this->redirectToRoute('app_avatars');
        }

        $user->setPicture($picture->getUrl());
        $em->flush();

        $this->addFlash('success', 'Avatar updated.');

        return $this->redirectToRoute('app_avatars');
    }
}
