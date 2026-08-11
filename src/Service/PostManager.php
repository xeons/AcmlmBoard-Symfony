<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Forum;
use App\Entity\Post;
use App\Entity\Thread;
use App\Entity\User;
use App\Repository\ForumRepository;
use App\Repository\PostLayoutRepository;
use App\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creating, editing and deleting posts, with every denormalised counter kept in
 * step inside one transaction.
 *
 * The original updated `users.posts`, `threads.replies`, `threads.lastpostdate`,
 * `threads.lastposter`, `forums.numposts`, `forums.lastpostdate` and
 * `forums.lastpostuser` with seven separate unguarded UPDATE statements. A failure
 * or a race between any two of them left the board permanently inconsistent, which
 * is why the original shipped with counts that visibly drifted. All of it commits or
 * none of it does here.
 */
final class PostManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PostRepository $posts,
        private readonly PostLayoutRepository $layouts,
        private readonly ForumRepository $forums,
        private readonly LayoutTokenExpander $tokens,
    ) {
    }

    /**
     * Creates the opening post of a new thread.
     */
    public function createThread(
        Forum $forum,
        User $author,
        string $title,
        string $body,
        ?string $ip = null,
        ?string $icon = null,
    ): Thread {
        return $this->em->wrapInTransaction(function () use ($forum, $author, $title, $body, $ip, $icon): Thread {
            $thread = new Thread($forum, $author, $title);
            $thread->setIcon($icon);
            $this->em->persist($thread);

            $post = $this->buildPost($thread, $author, $body, $ip);
            $this->em->persist($post);

            $author->incrementPosts();
            $author->setLastPostAt($post->getCreatedAt());

            $thread->setLastPostAt($post->getCreatedAt());
            $thread->setLastPoster($author);

            $forum->setThreadCount($forum->getThreadCount() + 1);
            $forum->setPostCount($forum->getPostCount() + 1);
            $forum->setLastPostAt($post->getCreatedAt());
            $forum->setLastPoster($author);

            $this->em->flush();

            return $thread;
        });
    }

    /**
     * Appends a reply.
     *
     * @param bool $closeAfter moderator's "close after posting" checkbox
     */
    public function reply(Thread $thread, User $author, string $body, ?string $ip = null, bool $closeAfter = false): Post
    {
        return $this->em->wrapInTransaction(function () use ($thread, $author, $body, $ip, $closeAfter): Post {
            $post = $this->buildPost($thread, $author, $body, $ip);
            $this->em->persist($post);

            $author->incrementPosts();
            $author->setLastPostAt($post->getCreatedAt());

            $thread->incrementReplies();
            $thread->setLastPostAt($post->getCreatedAt());
            $thread->setLastPoster($author);
            if ($closeAfter) {
                $thread->setClosed(true);
            }

            $forum = $thread->getForum();
            $forum->setPostCount($forum->getPostCount() + 1);
            $forum->setLastPostAt($post->getCreatedAt());
            $forum->setLastPoster($author);

            $this->em->flush();

            return $post;
        });
    }

    /**
     * Edits a post body. Only records an edit marker when someone other than the
     * author changed it, or when the author returns to it later - matching the
     * original's "edited by" line without stamping every immediate typo fix.
     */
    public function edit(Post $post, string $body, User $editor): void
    {
        $post->setBody($body);

        $isAuthor = $editor === $post->getAuthor();
        $graceExpired = $post->getCreatedAt() < new \DateTimeImmutable('-5 minutes');

        if (!$isAuthor || $graceExpired) {
            $post->markEdited($editor);
        }

        $this->em->flush();
    }

    /**
     * Deletes a post. Deleting the only post in a thread deletes the thread.
     *
     * The author's post count is decremented, which the original never did - so
     * deleting posts inflated everyone's rank permanently.
     */
    public function delete(Post $post): void
    {
        $this->em->wrapInTransaction(function () use ($post): void {
            $thread = $post->getThread();
            $forum = $thread->getForum();
            $author = $post->getAuthor();

            $isOnlyPost = 0 === $thread->getReplies();

            $this->em->remove($post);
            $this->em->flush();

            if (null !== $author) {
                $author->setPosts(max(0, $author->getPosts() - 1));
            }

            if ($isOnlyPost) {
                $this->em->remove($thread);
            } else {
                $thread->incrementReplies(-1);

                $last = $this->posts->findLastInThread($thread);
                if (null !== $last) {
                    $thread->setLastPostAt($last->getCreatedAt());
                    $thread->setLastPoster($last->getAuthor());
                }
            }

            // recount() derives the forum totals with SUM(threads.replies) straight
            // from the database, so the thread's new reply count has to be written
            // before it runs. Without this flush the forum is recounted from the
            // pre-deletion figure and ends up permanently one post too high - the
            // exact drift this class exists to prevent.
            $this->em->flush();

            $this->forums->recount($forum);
            $this->em->flush();
        });
    }

    /**
     * Deletes a whole thread and repairs the forum counters.
     */
    public function deleteThread(Thread $thread): void
    {
        $this->em->wrapInTransaction(function () use ($thread): void {
            $forum = $thread->getForum();

            // Give back the post counts the thread's authors were credited with.
            $counts = $this->em->getConnection()->fetchAllAssociative(
                'SELECT author_id, COUNT(*) AS n FROM posts WHERE thread_id = :tid AND author_id IS NOT NULL GROUP BY author_id',
                ['tid' => $thread->getId()],
            );
            foreach ($counts as $row) {
                $this->em->getConnection()->executeStatement(
                    'UPDATE users SET posts = GREATEST(0, posts - :n) WHERE id = :uid',
                    ['n' => (int) $row['n'], 'uid' => (int) $row['author_id']],
                );
            }

            // The posts are removed through the ORM rather than left to the database's
            // foreign-key cascade. The cascade would delete the rows either way, but
            // Doctrine does not know that: any Post already loaded in this request
            // still points at the thread, and flushing then fails with "a new entity
            // was found through the relationship Post#thread". Removing them
            // explicitly keeps the unit of work honest.
            foreach ($this->posts->findBy(['thread' => $thread]) as $post) {
                $this->em->remove($post);
            }

            $this->em->remove($thread);
            $this->em->flush();

            $this->forums->recount($forum);
            $this->em->flush();
        });
    }

    /**
     * Moves a thread between forums, fixing both forums' counters.
     */
    public function moveThread(Thread $thread, Forum $destination): void
    {
        $this->em->wrapInTransaction(function () use ($thread, $destination): void {
            $origin = $thread->getForum();
            if ($origin === $destination) {
                return;
            }

            $thread->setForum($destination);
            $this->em->flush();

            $this->forums->recount($origin);
            $this->forums->recount($destination);
            $this->em->flush();
        });
    }

    /**
     * Attaches a poll to a thread.
     *
     * @param list<string> $choices
     */
    public function attachPoll(Thread $thread, string $question, ?string $briefing, array $choices, bool $multiVote): ?\App\Entity\Poll
    {
        $choices = array_values(array_filter($choices, static fn (string $c): bool => '' !== trim($c)));

        if (\count($choices) < 2) {
            return null;
        }

        $poll = new \App\Entity\Poll();
        $poll->setQuestion($question);
        $poll->setBriefing($briefing);
        $poll->setMultiVote($multiVote);

        foreach ($choices as $position => $label) {
            $choice = new \App\Entity\PollChoice();
            $choice->setLabel(trim($label));
            $choice->setPosition($position);
            $poll->addChoice($choice);
        }

        $thread->setPoll($poll);

        $this->em->persist($poll);
        $this->em->flush();

        return $poll;
    }

    /**
     * Builds a post and freezes the author's layout and token values into it.
     */
    private function buildPost(Thread $thread, User $author, string $body, ?string $ip): Post
    {
        $post = new Post($thread, $author, $body);
        $post->setIp($ip);

        // The count *after* this post, which is what the "1234/5678" line shows.
        $postNumber = $author->getPosts() + 1;
        $post->setAuthorPostNumber($postNumber);

        $post->setHeaderLayout($this->layouts->findOrCreate($author->getPostHeader()));
        $post->setSignatureLayout($this->layouts->findOrCreate($author->getSignature()));
        $post->setTagValues($this->tokens->computeValues($author, $postNumber, $post->getCreatedAt()));

        return $post;
    }
}
