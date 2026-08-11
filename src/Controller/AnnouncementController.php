<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Announcement;
use App\Form\AnnouncementType;
use App\Repository\AnnouncementRepository;
use App\Repository\ForumRepository;
use App\Service\MarkupRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Board-wide and per-forum announcements.
 */
#[Route('/announcements')]
final class AnnouncementController extends AbstractBoardController
{
    #[Route('', name: 'app_announcements', methods: ['GET'])]
    public function index(AnnouncementRepository $announcements, MarkupRenderer $markup): Response
    {
        return $this->render('announcement/index.html.twig', [
            'announcements' => $announcements->findAllOrdered(),
            'markup' => $markup,
        ]);
    }

    #[Route('/new', name: 'app_announcement_new', methods: ['GET', 'POST'])]
    #[Route('/{id<\d+>}/edit', name: 'app_announcement_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MODERATOR')]
    public function edit(
        Request $request,
        EntityManagerInterface $em,
        ForumRepository $forums,
        ?Announcement $announcement = null,
    ): Response {
        $isNew = null === $announcement;
        $announcement ??= new Announcement($this->requireBoardUser(), '', '');

        $form = $this->createForm(AnnouncementType::class, $announcement, [
            'forums' => $forums->findForJumpMenu($this->viewerPower()),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $announcement->setIp($this->clientIp($request));
                $em->persist($announcement);
            } else {
                $announcement->markEdited();
            }

            $em->flush();
            $this->addFlash('success', $isNew ? 'Announcement posted.' : 'Announcement updated.');

            return $this->redirectToRoute('app_announcements');
        }

        return $this->render('announcement/edit.html.twig', [
            'form' => $form,
            'announcement' => $announcement,
            'isNew' => $isNew,
        ]);
    }

    #[Route('/{id<\d+>}/delete', name: 'app_announcement_delete', methods: ['POST'])]
    #[IsGranted('ROLE_MODERATOR')]
    public function delete(Announcement $announcement, Request $request, EntityManagerInterface $em): Response
    {
        $this->assertCsrf($request, 'delete-announcement'.$announcement->getId());

        $em->remove($announcement);
        $em->flush();

        $this->addFlash('success', 'Announcement deleted.');

        return $this->redirectToRoute('app_announcements');
    }
}
