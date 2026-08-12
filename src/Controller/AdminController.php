<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ActionLog;
use App\Entity\Category;
use App\Entity\Forum;
use App\Entity\ForumModerator;
use App\Entity\IpBan;
use App\Entity\User;
use App\Form\BoardConfigType;
use App\Form\CategoryType;
use App\Form\ForumType;
use App\Form\IpBanType;
use App\Form\UserAdminType;
use App\Repository\ActionLogRepository;
use App\Repository\BoardConfigRepository;
use App\Repository\CategoryRepository;
use App\Repository\ForumRepository;
use App\Repository\IpBanRepository;
use App\Repository\PostRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The administration panel.
 *
 * The original's admin/ directory was protected by nothing but a check that the
 * viewer's power level was 3 - performed inside each file, and omitted entirely from
 * admin/ipsearch.php. Access here is enforced by the firewall's access_control on
 * /admin as well as the attribute below.
 */
#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
final class AdminController extends AbstractBoardController
{
    #[Route('', name: 'app_admin', methods: ['GET'])]
    public function dashboard(
        UserRepository $users,
        ForumRepository $forums,
        ActionLogRepository $log,
        BoardConfigRepository $configs,
    ): Response {
        return $this->render('admin/dashboard.html.twig', [
            'userCount' => $users->countAll(),
            'forumCount' => \count($forums->findAll()),
            'recentActions' => iterator_to_array($log->paginate(1, 15)),
            'config' => $configs->get(),
        ]);
    }

    #[Route('/config', name: 'app_admin_config', methods: ['GET', 'POST'])]
    public function config(
        Request $request,
        BoardConfigRepository $configs,
        EntityManagerInterface $em,
    ): Response {
        $config = $configs->get();

        $form = $this->createForm(BoardConfigType::class, $config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist(new ActionLog(
                $this->requireBoardUser(),
                ActionLog::ACTION_CONFIG_CHANGE,
                null,
                [],
                $this->clientIp($request),
            ));
            $em->flush();

            $this->addFlash('success', 'Board configuration saved.');

            return $this->redirectToRoute('app_admin_config');
        }

        return $this->render('admin/config.html.twig', ['form' => $form]);
    }

    #[Route('/forums', name: 'app_admin_forums', methods: ['GET'])]
    public function forums(CategoryRepository $categories, ForumRepository $forums): Response
    {
        return $this->render('admin/forums.html.twig', [
            'categories' => $categories->findBy([], ['position' => 'ASC', 'id' => 'ASC']),
            'forums' => $forums->findBy([], ['position' => 'ASC', 'id' => 'ASC']),
        ]);
    }

    #[Route('/forums/new', name: 'app_admin_forum_new', methods: ['GET', 'POST'])]
    #[Route('/forums/{id<\d+>}', name: 'app_admin_forum_edit', methods: ['GET', 'POST'])]
    public function editForum(
        Request $request,
        EntityManagerInterface $em,
        CategoryRepository $categories,
        ?Forum $forum = null,
    ): Response {
        $isNew = null === $forum;
        $forum ??= new Forum();

        $form = $this->createForm(ForumType::class, $forum, [
            'categories' => $categories->findBy([], ['position' => 'ASC']),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $em->persist($forum);
            }
            $em->flush();

            $this->addFlash('success', $isNew ? 'Forum created.' : 'Forum updated.');

            return $this->redirectToRoute('app_admin_forums');
        }

        return $this->render('admin/forum_edit.html.twig', [
            'form' => $form,
            'forum' => $forum,
            'isNew' => $isNew,
        ]);
    }

    #[Route('/categories/new', name: 'app_admin_category_new', methods: ['GET', 'POST'])]
    #[Route('/categories/{id<\d+>}', name: 'app_admin_category_edit', methods: ['GET', 'POST'])]
    public function editCategory(
        Request $request,
        EntityManagerInterface $em,
        ?Category $category = null,
    ): Response {
        $isNew = null === $category;
        $category ??= new Category();

        $form = $this->createForm(CategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $em->persist($category);
            }
            $em->flush();

            $this->addFlash('success', $isNew ? 'Category created.' : 'Category updated.');

            return $this->redirectToRoute('app_admin_forums');
        }

        return $this->render('admin/category_edit.html.twig', [
            'form' => $form,
            'category' => $category,
            'isNew' => $isNew,
        ]);
    }

    /** Grants and revokes local moderation of a forum. */
    #[Route('/forums/{id<\d+>}/moderators', name: 'app_admin_forum_moderators', methods: ['GET', 'POST'])]
    public function moderators(
        Forum $forum,
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
    ): Response {
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'moderators'.$forum->getId());

            $removing = 'remove' === $request->request->get('action');

            // Removal comes from a button the server rendered, so it carries the
            // id. Adding comes from a name somebody typed. Keeping them on
            // separate fields means a member whose name happens to be a number
            // cannot be confused with the row id of another.
            $target = $removing
                ? $users->find($request->request->getInt('user'))
                : $users->findOneByUsername(trim((string) $request->request->get('member', '')));

            if (null === $target) {
                $this->addFlash('error', 'No member by that name.');

                return $this->redirectToRoute('app_admin_forum_moderators', ['id' => $forum->getId()]);
            }

            if ($removing) {
                foreach ($forum->getModerators() as $moderator) {
                    if ($moderator->getUser() === $target) {
                        $em->remove($moderator);
                    }
                }
                $this->addFlash('success', \sprintf('%s is no longer a moderator here.', $target->getUsername()));
            } elseif (!$forum->isModeratedBy($target)) {
                $em->persist(new ForumModerator($forum, $target));
                $this->addFlash('success', \sprintf('%s now moderates this forum.', $target->getUsername()));
            }

            $em->flush();

            return $this->redirectToRoute('app_admin_forum_moderators', ['id' => $forum->getId()]);
        }

        return $this->render('admin/forum_moderators.html.twig', ['forum' => $forum]);
    }

    #[Route('/users/{id<\d+>}', name: 'app_admin_user_edit', methods: ['GET', 'POST'])]
    public function editUser(
        User $user,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        $actor = $this->requireBoardUser();

        // An admin cannot promote themselves, or edit someone above them. The
        // original let any admin set any power level on anyone, including demoting
        // the owner.
        if ($user->getPowerLevel()->value > $actor->getPowerLevel()->value) {
            throw $this->createAccessDeniedException('That account outranks you.');
        }

        $form = $this->createForm(UserAdminType::class, $user, [
            'max_power' => $actor->getPowerLevel(),
            'is_self' => $user === $actor,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist(new ActionLog(
                $actor,
                ActionLog::ACTION_USER_EDIT,
                'user:'.$user->getId(),
                ['powerLevel' => $user->getPowerLevel()->value],
                $this->clientIp($request),
            ));
            $em->flush();

            $this->addFlash('success', \sprintf('%s updated.', $user->getUsername()));

            return $this->redirectToRoute('app_admin_user_edit', ['id' => $user->getId()]);
        }

        return $this->render('admin/user_edit.html.twig', [
            'form' => $form,
            'profile' => $user,
        ]);
    }

    #[Route('/ip-bans', name: 'app_admin_ip_bans', methods: ['GET', 'POST'])]
    public function ipBans(
        Request $request,
        IpBanRepository $bans,
        EntityManagerInterface $em,
        \App\Service\IpBanChecker $checker,
    ): Response {
        $ban = new IpBan('', $this->requireBoardUser());

        $form = $this->createForm(IpBanType::class, $ban);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($ban);
            $em->persist(new ActionLog(
                $this->requireBoardUser(),
                ActionLog::ACTION_IP_BAN,
                null,
                ['range' => $ban->getIpRange()],
                $this->clientIp($request),
            ));
            $em->flush();
            $checker->invalidate();

            $this->addFlash('success', \sprintf('%s is now banned.', $ban->getIpRange()));

            return $this->redirectToRoute('app_admin_ip_bans');
        }

        return $this->render('admin/ip_bans.html.twig', [
            'form' => $form,
            'bans' => $bans->findActive(),
        ]);
    }

    #[Route('/ip-bans/{id<\d+>}/delete', name: 'app_admin_ip_ban_delete', methods: ['POST'])]
    public function deleteIpBan(
        IpBan $ban,
        Request $request,
        EntityManagerInterface $em,
        \App\Service\IpBanChecker $checker,
    ): Response {
        $this->assertCsrf($request, 'delete-ip-ban'.$ban->getId());

        $range = $ban->getIpRange();
        $em->remove($ban);
        $em->flush();

        // Lifting a ban must also take effect at once, or an unbanned user stays
        // locked out for the remainder of the cache window.
        $checker->invalidate();

        $this->addFlash('success', \sprintf('Ban on %s lifted.', $range));

        return $this->redirectToRoute('app_admin_ip_bans');
    }

    /** Finds every account and post associated with an address. */
    #[Route('/ip-search', name: 'app_admin_ip_search', methods: ['GET'])]
    public function ipSearch(
        Request $request,
        UserRepository $users,
        PostRepository $posts,
        ForumRepository $forums,
    ): Response {
        $ip = trim((string) $request->query->get('ip', ''));

        $matchedUsers = [];
        $matchedPosts = [];

        if ('' !== $ip) {
            $matchedUsers = $users->findByLastIp($ip);
            $matchedPosts = iterator_to_array($posts->search(
                visibleForumIds: $forums->findReadableIds($this->viewerPower()),
                ip: $ip,
                page: 1,
                perPage: 50,
            ));
        }

        return $this->render('admin/ip_search.html.twig', [
            'ip' => $ip,
            'users' => $matchedUsers,
            'posts' => $matchedPosts,
        ]);
    }

    #[Route('/log', name: 'app_admin_log', methods: ['GET'])]
    public function log(Request $request, ActionLogRepository $log): Response
    {
        $page = $this->pageFrom($request);
        $paginator = $log->paginate($page, 50, $request->query->get('action') ?: null);

        return $this->render('admin/log.html.twig', [
            'entries' => iterator_to_array($paginator),
            'total' => \count($paginator),
            'page' => $page,
            'pageCount' => max(1, (int) ceil(\count($paginator) / 50)),
        ]);
    }
}
