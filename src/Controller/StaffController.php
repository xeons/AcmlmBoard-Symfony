<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ForumBanType;
use App\Form\SoftBanType;
use App\Form\SystemMessageType;
use App\Repository\ForumBanRepository;
use App\Repository\PunishmentRepository;
use App\Repository\SoftBanRepository;
use App\Service\MessageManager;
use App\Service\ModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The staff panel: disciplinary tracker, soft bans, forum bans and system messages.
 * This is the /ppanel directory of the original, which had no access check at all -
 * every file in it was reachable by anyone who knew the URL.
 */
#[Route('/staff')]
#[IsGranted('ROLE_STAFF')]
final class StaffController extends AbstractBoardController
{
    #[Route('', name: 'app_staff', methods: ['GET'])]
    public function index(
        PunishmentRepository $punishments,
        SoftBanRepository $softBans,
        ForumBanRepository $forumBans,
    ): Response {
        return $this->render('staff/index.html.twig', [
            'records' => $punishments->findAllWithUsers(),
            'softBans' => $softBans->findAllActive(),
        ]);
    }

    /** The disciplinary record for one member, with its staff-only comment thread. */
    #[Route('/user/{id<\d+>}', name: 'app_staff_user', methods: ['GET'])]
    public function user(
        User $user,
        ModerationService $moderation,
        ForumBanRepository $forumBans,
        SoftBanRepository $softBans,
    ): Response {
        return $this->render('staff/user.html.twig', [
            'profile' => $user,
            'record' => $moderation->punishmentFor($user, $this->requireBoardUser()),
            'forumBans' => $forumBans->findActiveFor($user),
            'softBan' => $softBans->findActiveFor($user),
        ]);
    }

    #[Route('/user/{id<\d+>}/strike', name: 'app_staff_strike', methods: ['POST'])]
    public function strike(
        User $user,
        Request $request,
        ModerationService $moderation,
        EntityManagerInterface $em,
    ): Response {
        $this->assertCsrf($request, 'strike'.$user->getId());

        $record = $moderation->punishmentFor($user, $this->requireBoardUser());

        if ('remove' === $request->request->get('action')) {
            $record->setStrikes(max(0, $record->getStrikes() - 1));
        } else {
            $record->addStrike();
        }

        $em->flush();
        $this->addFlash('success', \sprintf('%s now has %d strike(s).', $user->getUsername(), $record->getStrikes()));

        return $this->redirectToRoute('app_staff_user', ['id' => $user->getId()]);
    }

    #[Route('/user/{id<\d+>}/soft-ban', name: 'app_staff_soft_ban', methods: ['GET', 'POST'])]
    public function softBan(
        User $user,
        Request $request,
        ModerationService $moderation,
    ): Response {
        $form = $this->createForm(SoftBanType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $moderation->softBan(
                $user,
                $data['expiresAt'] ?? null,
                $this->requireBoardUser(),
                $data['reason'] ?? null,
                $this->clientIp($request),
            );

            $this->addFlash('success', \sprintf('%s can no longer post.', $user->getUsername()));

            return $this->redirectToRoute('app_staff_user', ['id' => $user->getId()]);
        }

        return $this->render('staff/soft_ban.html.twig', ['profile' => $user, 'form' => $form]);
    }

    #[Route('/soft-ban/{id<\d+>}/lift', name: 'app_staff_soft_ban_lift', methods: ['POST'])]
    public function liftSoftBan(
        \App\Entity\SoftBan $ban,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        $this->assertCsrf($request, 'lift-ban'.$ban->getId());

        $userId = $ban->getUser()->getId();
        $em->remove($ban);
        $em->flush();

        $this->addFlash('success', 'Suspension lifted.');

        return $this->redirectToRoute('app_staff_user', ['id' => $userId]);
    }

    #[Route('/user/{id<\d+>}/forum-ban', name: 'app_staff_forum_ban', methods: ['GET', 'POST'])]
    public function forumBan(
        User $user,
        Request $request,
        ModerationService $moderation,
        \App\Repository\BoardConfigRepository $configs,
        \App\Repository\ForumRepository $forums,
    ): Response {
        // The board config decides what power level may issue forum bans.
        $required = $configs->get()->getForumBanMinPower();
        if ($this->viewerPower() < $required) {
            throw $this->createAccessDeniedException('You are not allowed to issue forum bans.');
        }

        $form = $this->createForm(ForumBanType::class, null, [
            'forums' => $forums->findForJumpMenu($this->viewerPower()),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $moderation->forumBan(
                $user,
                $data['forum'],
                $data['expiresAt'] ?? null,
                $this->requireBoardUser(),
                $data['reason'] ?? null,
                $this->clientIp($request),
            );

            $this->addFlash('success', \sprintf(
                '%s is banned from %s.',
                $user->getUsername(),
                $data['forum']->getTitle(),
            ));

            return $this->redirectToRoute('app_staff_user', ['id' => $user->getId()]);
        }

        return $this->render('staff/forum_ban.html.twig', ['profile' => $user, 'form' => $form]);
    }

    #[Route('/forum-ban/{id<\d+>}/lift', name: 'app_staff_forum_ban_lift', methods: ['POST'])]
    public function liftForumBan(
        \App\Entity\ForumBan $ban,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        $this->assertCsrf($request, 'lift-forum-ban'.$ban->getId());

        $userId = $ban->getUser()->getId();
        $em->remove($ban);
        $em->flush();

        $this->addFlash('success', 'Forum ban lifted.');

        return $this->redirectToRoute('app_staff_user', ['id' => $userId]);
    }

    /**
     * Sends a system message, which blocks the recipient from posting until read.
     */
    #[Route('/user/{id<\d+>}/system-message', name: 'app_staff_system_message', methods: ['GET', 'POST'])]
    public function systemMessage(
        User $user,
        Request $request,
        MessageManager $messages,
        \App\Repository\BoardConfigRepository $configs,
    ): Response {
        $form = $this->createForm(SystemMessageType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $messages->sendSystem(
                $configs->get()->getSystemAccount() ?? $this->requireBoardUser(),
                $user,
                (string) $data['title'],
                (string) $data['body'],
            );

            $this->addFlash('success', \sprintf('System message sent to %s.', $user->getUsername()));

            return $this->redirectToRoute('app_staff_user', ['id' => $user->getId()]);
        }

        return $this->render('staff/system_message.html.twig', ['profile' => $user, 'form' => $form]);
    }

}
