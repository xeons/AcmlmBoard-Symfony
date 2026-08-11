<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Forum;
use App\Entity\Post;
use App\Entity\Thread;
use App\Service\PostManager;
use App\Tests\Support\BoardWebTestCase;

/**
 * Posting, editing and deleting, and the denormalised counters that hang off them.
 *
 * The original updated users.posts, threads.replies, threads.lastpostdate,
 * threads.lastposter, forums.numposts, forums.lastpostdate and forums.lastpostuser
 * with seven separate unguarded statements, and never decremented anything on
 * delete - so counts drifted permanently and every install eventually shipped with
 * visibly wrong numbers. The port does it in one transaction; these tests are what
 * say so.
 */
final class PostingTest extends BoardWebTestCase
{
    // ------------------------------------------------------------------
    // Creating
    // ------------------------------------------------------------------

    public function testAMemberCanStartAThreadThroughTheForm(): void
    {
        $this->signInAs('Member');
        $forumId = $this->id('forum', 'General discussion');

        $this->client->request('GET', '/forum/'.$forumId.'/new-thread');
        $this->client->submitForm('Post thread', [
            'new_thread[title]' => 'A thread made by the form',
            'new_thread[body]' => 'With a body that says something.',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->client->followRedirect();
        self::assertStringContainsString('With a body that says something.', $this->client->getResponse()->getContent());
    }

    public function testAMemberCanReplyThroughTheForm(): void
    {
        // Member, not Other: Other posted the seeded thread's last reply, and the
        // board refuses consecutive replies from the same person.
        $this->signInAs('Member');
        $threadId = $this->id('thread', 'open');

        $this->client->request('GET', '/thread/'.$threadId.'/reply');
        $this->client->submitForm('Submit reply', [
            'reply[body]' => 'A reply written through the form.',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->client->request('GET', '/thread/'.$threadId);
        self::assertStringContainsString('A reply written through the form.', $this->client->getResponse()->getContent());
    }

    /**
     * The error summary walks the form's children, and buttons are children too.
     * A ButtonView has no "errors" key at all, so reading it is a hard Twig failure
     * rather than an empty result - which took out every form carrying a submit
     * button the moment the summary was added.
     */
    public function testFormsWithButtonsRenderTheirErrorSummary(): void
    {
        $this->signInAs('Member');

        // Rendering alone is enough to hit it: the summary is always included.
        $this->assertPageLoads('/thread/'.$this->id('thread', 'open').'/reply');
        $this->assertPageLoads('/forum/'.$this->id('forum', 'General discussion').'/new-thread');

        // And again with something actually wrong, so the summary really builds.
        $this->client->request('GET', '/forum/'.$this->id('forum', 'General discussion').'/new-thread');
        $crawler = $this->client->submitForm('Post thread', [
            'new_thread[title]' => '',
            'new_thread[body]' => '',
        ]);

        self::assertFalse($this->client->getResponse()->isRedirect(), 'An empty thread was accepted.');
        self::assertGreaterThan(
            0,
            $crawler->filter('.flash-error')->count(),
            'A rejected post showed no error summary.',
        );
    }

    /** Previewing must not save anything - and must not crash, which it used to. */
    public function testPreviewingAThreadShowsItWithoutSavingIt(): void
    {
        $this->signInAs('Member');
        $forumId = $this->id('forum', 'General discussion');
        $before = $this->threadCount();

        $this->client->request('GET', '/forum/'.$forumId.'/new-thread');
        $this->client->submitForm('Preview thread', [
            'new_thread[title]' => 'Previewed but never posted',
            'new_thread[body]' => 'This text should appear in the preview.',
        ]);

        $this->assertStatus(200);
        self::assertStringContainsString('This text should appear in the preview.', $this->client->getResponse()->getContent());
        self::assertSame($before, $this->threadCount(), 'Previewing created a thread.');
    }

    public function testPreviewingAReplyShowsItWithoutSavingIt(): void
    {
        $this->signInAs('Member');
        $threadId = $this->id('thread', 'open');
        $before = $this->postCount();

        $this->client->request('GET', '/thread/'.$threadId.'/reply');
        $this->client->submitForm('Preview reply', [
            'reply[body]' => 'A preview of a reply.',
        ]);

        $this->assertStatus(200);
        self::assertStringContainsString('A preview of a reply.', $this->client->getResponse()->getContent());
        self::assertSame($before, $this->postCount(), 'Previewing created a post.');
    }

    // ------------------------------------------------------------------
    // Counters
    // ------------------------------------------------------------------

    public function testStartingAThreadUpdatesEveryCounter(): void
    {
        $author = $this->user('Member');
        $forum = $this->forum('Introductions');

        $posts = $author->getPosts();
        $threads = $forum->getThreadCount();
        $forumPosts = $forum->getPostCount();

        $thread = $this->manager()->createThread($forum, $author, 'Counting', 'One.', '203.0.113.99');

        self::assertSame($posts + 1, $author->getPosts(), 'The author was not credited.');
        self::assertSame($threads + 1, $forum->getThreadCount());
        self::assertSame($forumPosts + 1, $forum->getPostCount());
        self::assertSame($author, $forum->getLastPoster());
        self::assertSame($author, $thread->getLastPoster());
        self::assertSame(0, $thread->getReplies(), 'The opening post is not a reply.');
    }

    public function testReplyingUpdatesEveryCounter(): void
    {
        $thread = $this->thread('open');
        $forum = $thread->getForum();
        $author = $this->user('Other');

        $posts = $author->getPosts();
        $replies = $thread->getReplies();
        $forumPosts = $forum->getPostCount();
        $threads = $forum->getThreadCount();

        $this->manager()->reply($thread, $author, 'Another reply.', '203.0.113.99');

        self::assertSame($posts + 1, $author->getPosts());
        self::assertSame($replies + 1, $thread->getReplies());
        self::assertSame($forumPosts + 1, $forum->getPostCount());
        self::assertSame($threads, $forum->getThreadCount(), 'Replying is not a new thread.');
        self::assertSame($author, $thread->getLastPoster());
    }

    /**
     * The forum total is recomputed from the database on delete, so the thread's new
     * reply count has to reach the database first. It did not, and the forum ended
     * up one post too high after every single deletion - permanently, since the
     * wrong figure was then written back.
     */
    public function testDeletingAPostLeavesTheForumTotalCorrectInTheDatabase(): void
    {
        $thread = $this->thread('open');
        $forum = $thread->getForum();

        $post = $this->manager()->reply($thread, $this->user('Other'), 'Doomed.', null);
        $this->manager()->delete($post);

        $actual = (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM posts p JOIN threads t ON t.id = p.thread_id WHERE t.forum_id = ?',
            [$forum->getId()],
        );
        $stored = (int) $this->em()->getConnection()->fetchOne(
            'SELECT post_count FROM forums WHERE id = ?',
            [$forum->getId()],
        );

        self::assertSame($actual, $stored, 'The stored forum post count drifted from the real number.');
    }

    /**
     * A member may not follow themselves in a thread. Staff are exempt, as they were
     * in the original.
     */
    public function testAMemberCannotPostTwiceInARow(): void
    {
        $thread = $this->thread('open');
        $lastPoster = $thread->getLastPoster();

        $reason = $this->container()->get(\App\Service\PostingGuard::class)
            ->refusalReasonForReply($lastPoster, $thread);

        self::assertStringContainsString('last reply', (string) $reason);
    }

    /** The original never did this, so deleting posts inflated ranks permanently. */
    public function testDeletingAPostGivesBackTheAuthorsPostCount(): void
    {
        $thread = $this->thread('open');
        $author = $this->user('Other');
        $post = $this->manager()->reply($thread, $author, 'Doomed.', '203.0.113.99');

        $posts = $author->getPosts();
        $replies = $thread->getReplies();
        $forumPosts = $thread->getForum()->getPostCount();

        $this->manager()->delete($post);

        self::assertSame($posts - 1, $author->getPosts());
        self::assertSame($replies - 1, $thread->getReplies());
        self::assertSame($forumPosts - 1, $thread->getForum()->getPostCount());
        self::assertSame($forumPosts - 1, $this->storedPostCount($thread->getForum()));
    }

    public function testDeletingTheOnlyPostRemovesTheThreadToo(): void
    {
        $forum = $this->forum('Introductions');
        $thread = $this->manager()->createThread($forum, $this->user('Member'), 'Briefly here', 'Bye.', null);
        $threadId = $thread->getId();

        $threads = $forum->getThreadCount();

        $only = $this->em()->getRepository(Post::class)->findOneBy(['thread' => $thread]);
        $this->manager()->delete($only);

        self::assertNull($this->em()->find(Thread::class, $threadId), 'The empty thread survived.');
        self::assertSame($threads - 1, $forum->getThreadCount());
    }

    public function testDeletingAThreadReturnsPostCountsToEveryAuthor(): void
    {
        $forum = $this->forum('Introductions');
        $one = $this->user('Member');
        $two = $this->user('Other');

        $thread = $this->manager()->createThread($forum, $one, 'Shared thread', 'Mine.', null);
        $this->manager()->reply($thread, $two, 'And mine.', null);
        $this->manager()->reply($thread, $two, 'And another of mine.', null);

        $onePosts = $one->getPosts();
        $twoPosts = $two->getPosts();

        $this->manager()->deleteThread($thread);
        $this->em()->clear();

        self::assertSame($onePosts - 1, $this->user('Member')->getPosts());
        self::assertSame($twoPosts - 2, $this->user('Other')->getPosts(), 'Both of this author\'s posts should be returned.');
    }

    public function testPostCountsNeverGoNegative(): void
    {
        $forum = $this->forum('Introductions');
        $author = $this->user('Banned');
        $author->setPosts(0);
        $this->em()->flush();

        $thread = $this->manager()->createThread($forum, $author, 'Zeroed', 'Text.', null);
        $post = $this->em()->getRepository(Post::class)->findOneBy(['thread' => $thread]);

        $author->setPosts(0);
        $this->em()->flush();

        $this->manager()->delete($post);

        self::assertSame(0, $author->getPosts(), 'A post count went negative.');
    }

    public function testMovingAThreadFixesBothForumsCounters(): void
    {
        $origin = $this->forum('General discussion');
        $destination = $this->forum('Introductions');
        $thread = $this->thread('open');

        $originThreads = $origin->getThreadCount();
        $originPosts = $origin->getPostCount();
        $moved = 1 + $thread->getReplies();

        $this->manager()->moveThread($thread, $destination);

        self::assertSame($originThreads - 1, $origin->getThreadCount());
        self::assertSame($originPosts - $moved, $origin->getPostCount());
        self::assertSame(1, $destination->getThreadCount());
        self::assertSame($moved, $destination->getPostCount());
    }

    public function testMovingAThreadToItsOwnForumChangesNothing(): void
    {
        $forum = $this->forum('General discussion');
        $threads = $forum->getThreadCount();
        $posts = $forum->getPostCount();

        $this->manager()->moveThread($this->thread('open'), $forum);

        self::assertSame($threads, $forum->getThreadCount());
        self::assertSame($posts, $forum->getPostCount());
    }

    // ------------------------------------------------------------------
    // Editing
    // ------------------------------------------------------------------

    /**
     * An immediate correction by the author leaves no marker - the original stamped
     * every edit, so fixing a typo five seconds later announced itself forever.
     */
    public function testAnImmediateSelfEditIsNotStamped(): void
    {
        $post = $this->em()->find(Post::class, $this->id('post', 'first'));

        $this->manager()->edit($post, 'Corrected straight away.', $this->user('Member'));

        self::assertNull($post->getEditedAt());
        self::assertSame('Corrected straight away.', $post->getBody());
    }

    public function testALateSelfEditIsStamped(): void
    {
        $post = $this->em()->find(Post::class, $this->id('post', 'first'));
        $post->setCreatedAt(new \DateTimeImmutable('-1 hour'));
        $this->em()->flush();

        $this->manager()->edit($post, 'Thought better of it.', $this->user('Member'));

        self::assertNotNull($post->getEditedAt());
        self::assertSame($this->user('Member'), $post->getEditedBy());
    }

    /** Somebody else's edit is always recorded, however quickly it happens. */
    public function testAModeratorsEditIsAlwaysStamped(): void
    {
        $post = $this->em()->find(Post::class, $this->id('post', 'first'));

        $this->manager()->edit($post, 'Edited by staff.', $this->user('Mod'));

        self::assertNotNull($post->getEditedAt());
        self::assertSame($this->user('Mod'), $post->getEditedBy());
    }

    // ------------------------------------------------------------------
    // Polls
    // ------------------------------------------------------------------

    public function testAPollNeedsAtLeastTwoChoices(): void
    {
        $thread = $this->thread('open');

        self::assertNull($this->manager()->attachPoll($thread, 'One horse race?', null, ['Only option'], false));
        self::assertNull($this->manager()->attachPoll($thread, 'Nothing?', null, [], false));
        // Blank choices are discarded before counting, so this is still one choice.
        self::assertNull($this->manager()->attachPoll($thread, 'Padded?', null, ['Yes', '  ', ''], false));
    }

    public function testAPollWithTwoChoicesIsAttached(): void
    {
        $thread = $this->thread('open');

        $poll = $this->manager()->attachPoll($thread, 'Well?', 'Pick one.', ['Yes', 'No'], false);

        self::assertNotNull($poll);
        self::assertCount(2, $poll->getChoices());
        self::assertSame($poll, $thread->getPoll());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function manager(): PostManager
    {
        return $this->container()->get(PostManager::class);
    }

    private function forum(string $title): Forum
    {
        return $this->em()->find(Forum::class, $this->id('forum', $title));
    }

    private function thread(string $key): Thread
    {
        return $this->em()->find(Thread::class, $this->id('thread', $key));
    }

    private function threadCount(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM threads');
    }

    private function postCount(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM posts');
    }

    /** The persisted counter, not the in-memory one. */
    private function storedPostCount(Forum $forum): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT post_count FROM forums WHERE id = ?',
            [$forum->getId()],
        );
    }
}
