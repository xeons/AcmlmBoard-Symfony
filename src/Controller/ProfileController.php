<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserRating;
use App\Form\EditProfileType;
use App\Repository\ForumBanRepository;
use App\Repository\ForumRepository;
use App\Repository\PostRepository;
use App\Repository\ThreadRepository;
use App\Repository\UserRatingRepository;
use App\Repository\UserRepository;
use App\Security\Voter\ProfileVoter;
use App\Service\LevelCalculator;
use App\Service\RankResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Viewing and editing user profiles.
 */
final class ProfileController extends AbstractBoardController
{
    #[Route('/profile/{id<\d+>}', name: 'app_profile', methods: ['GET'])]
    public function show(
        User $user,
        UserRepository $users,
        UserRatingRepository $ratings,
        ThreadRepository $threads,
        PostRepository $posts,
        ForumBanRepository $forumBans,
        LevelCalculator $levels,
        RankResolver $ranks,
    ): Response {
        $viewer = $this->boardUser();
        $experience = $levels->experienceFor($user);

        return $this->render('profile/show.html.twig', [
            'profile' => $user,
            'rankBlock' => $ranks->resolveBlock($user),
            'experience' => $experience,
            'level' => $levels->level($experience),
            'experienceToNext' => $levels->experienceToNextLevel($experience),
            'levelProgress' => $levels->levelProgress($experience),
            'experiencePerPost' => $levels->experiencePerPost($user->getPosts(), $user->daysRegistered()),
            'experiencePerSecond' => $levels->experiencePerSecond($user->getPosts(), $user->daysRegistered()),
            'postRank' => $users->getPostRank($user),
            'postsToNextRank' => $users->getPostsToNextRank($user),
            // The stored post count can drift from reality if posts were deleted
            // before the recount command last ran, so show both.
            'actualPostCount' => $posts->countByAuthor($user),
            'threadCount' => $threads->countByAuthor($user),
            'ratings' => $ratings->getSummaryFor($user),
            'myRating' => null !== $viewer ? $ratings->findByPair($viewer, $user)?->getRating() : null,
            'canSeeEmail' => $this->isGranted(ProfileVoter::VIEW_EMAIL, $user),
            'canSeeIp' => $this->isGranted(ProfileVoter::VIEW_IP, $user),
            'canRate' => $this->isGranted(ProfileVoter::RATE, $user),
            'canMessage' => $this->isGranted(ProfileVoter::SEND_MESSAGE, $user),
            'layoutBlocked' => null !== $viewer && $viewer->hasBlockedLayoutOf($user),
            'forumBans' => $this->isGranted('ROLE_STAFF') ? $forumBans->findActiveFor($user) : [],
        ]);
    }

    #[Route('/profile/edit', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        \App\Repository\BoardConfigRepository $configs,
        \App\Service\PasskeyService $passkeys,
    ): Response {
        $user = $this->requireBoardUser();
        $config = $configs->get();

        // Custom titles are earned: enough posts, or enough posts plus enough time.
        // titleOption 0 revokes the privilege, 2 grants it outright.
        $canSetTitle = match ($user->getTitleOption()) {
            0 => false,
            2 => true,
            default => $user->getPosts() >= $config->getCustomTitlePostThreshold()
                || ($user->getPosts() >= $config->getCustomTitleAgePostThreshold()
                    && $user->daysRegistered() >= $config->getCustomTitleAgeDayThreshold())
                || $user->isStaff()
                || null !== $user->getTitle(),
        };

        $form = $this->createForm(EditProfileType::class, $user, [
            'can_set_title' => $canSetTitle && !$user->isBanned(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = $form->get('plainPassword')->getData();

            if (\is_string($newPassword) && '' !== $newPassword) {
                $user->setPassword($hasher->hashPassword($user, $newPassword));
                // A password set here is a real one, so any legacy md5 is retired.
                $user->setPasswordLegacyMd5(false);
            }

            $em->flush();
            $this->addFlash('success', 'Profile updated.');

            return $this->redirectToRoute('app_profile', ['id' => $user->getId()]);
        }

        return $this->render('profile/edit.html.twig', [
            'form' => $form,
            'canSetTitle' => $canSetTitle,
            // Shown beside the passkey link, so the account section says how you can
            // currently sign in rather than just offering a link to find out.
            'passkeyCount' => $passkeys->countFor($user),
            'totpActive' => $user->isTotpAuthenticationEnabled(),
        ]);
    }

    #[Route('/profile/{id<\d+>}/rate', name: 'app_profile_rate', methods: ['POST'])]
    public function rate(
        User $user,
        Request $request,
        UserRatingRepository $ratings,
        EntityManagerInterface $em,
    ): Response {
        $this->denyAccessUnlessGranted(ProfileVoter::RATE, $user);
        $this->assertCsrf($request, 'rate'.$user->getId());

        $rater = $this->requireBoardUser();
        $value = $request->request->getInt('rating');

        // One rating per pair: re-rating replaces rather than accumulating, which is
        // what the original achieved with a DELETE followed by an INSERT.
        $existing = $ratings->findByPair($rater, $user);
        if (null !== $existing) {
            $existing->setRating($value);
        } else {
            $em->persist(new UserRating($rater, $user, $value));
        }

        $em->flush();
        $this->addFlash('success', \sprintf('You rated %s %d/10.', $user->getUsername(), max(0, min(10, $value))));

        return $this->redirectToRoute('app_profile', ['id' => $user->getId()]);
    }

    #[Route('/profile/{id<\d+>}/ratings', name: 'app_profile_ratings', methods: ['GET'])]
    public function ratings(User $user, UserRatingRepository $ratings): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('profile/ratings.html.twig', [
            'profile' => $user,
            'received' => $ratings->findReceivedBy($user),
            'given' => $ratings->findGivenBy($user),
        ]);
    }

    /** Blocking a user's post layout, so their signature stops rendering for you. */
    #[Route('/profile/{id<\d+>}/block-layout', name: 'app_layout_block', methods: ['POST'])]
    public function blockLayout(User $user, Request $request, EntityManagerInterface $em): Response
    {
        $viewer = $this->requireBoardUser();
        $this->assertCsrf($request, 'block'.$user->getId());

        if ($viewer === $user) {
            $this->addFlash('error', 'You cannot block your own layout.');

            return $this->redirectToRoute('app_profile', ['id' => $user->getId()]);
        }

        if ($viewer->hasBlockedLayoutOf($user)) {
            $viewer->unblockLayoutOf($user);
            $this->addFlash('success', \sprintf("%s's layout is visible again.", $user->getUsername()));
        } else {
            $viewer->blockLayoutOf($user);
            $this->addFlash('success', \sprintf("%s's layout is now blocked.", $user->getUsername()));
        }

        $em->flush();

        return $this->redirectToRoute('app_profile', ['id' => $user->getId()]);
    }

    #[Route('/blocked-layouts', name: 'app_layout_blocked_list', methods: ['GET'])]
    public function blockedLayouts(): Response
    {
        return $this->render('profile/blocked_layouts.html.twig', [
            'blocked' => $this->requireBoardUser()->getBlockedLayouts(),
        ]);
    }
}
