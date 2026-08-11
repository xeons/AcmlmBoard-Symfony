<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Expands the &token& vocabulary that users embed in post headers and signatures -
 * &numposts&, &level&, &exppct&, &postrank& and friends.
 *
 * Two things were wrong with the original dotag()/doreplace() pair:
 *
 *   - Each &postrank*& token ran its own `SELECT count(*) FROM users WHERE posts>n`.
 *     With four rank tokens and a header and a signature, a 20-post page could fire
 *     160 aggregate counts against the users table. Ranks are resolved once here and
 *     shared across every token on the page.
 *   - Values were packed into a single `tagval` TEXT column delimited by a chr(255)
 *     pair, then unpacked by scanning for that byte sequence - so any user whose
 *     signature happened to contain that byte corrupted the whole record. Frozen
 *     values are JSON now.
 */
final class LayoutTokenExpander
{
    public function __construct(
        private readonly LevelCalculator $levels,
        private readonly UserRepository $users,
        private readonly RankResolver $ranks,
    ) {
    }

    /**
     * Computes every token value for a user at a point in time.
     *
     * @param int|null $postNumber the author's post count as of the post being
     *                             rendered; null means "use their current count"
     *
     * @return array<string, string>
     */
    public function computeValues(User $user, ?int $postNumber = null, ?\DateTimeImmutable $at = null): array
    {
        $at ??= new \DateTimeImmutable();
        $posts = $postNumber ?? $user->getPosts();
        $days = max(0.0, ($at->getTimestamp() - $user->getRegisteredAt()->getTimestamp()) / 86400);

        $exp = $this->levels->experience($posts, $days);
        $level = $this->levels->level($exp);
        $span = $this->levels->levelSpan($level);
        $done = $this->levels->experienceIntoLevel($exp);
        $next = $this->levels->experienceToNextLevel($exp);

        $values = [
            '&numposts&' => (string) $posts,
            '&numdays&' => (string) (int) floor($days),
            '&exp&' => (string) $exp,
            '&level&' => (string) $level,
            '&lvlexp&' => (string) $this->levels->experienceForLevel($level + 1),
            '&lvllen&' => (string) $span,
            '&expdone&' => (string) $done,
            '&expnext&' => (string) $next,
            '&expdone1k&' => (string) (int) floor($done / 1000),
            '&expnext1k&' => (string) (int) floor($next / 1000),
            '&expdone10k&' => (string) (int) floor($done / 10000),
            '&expnext10k&' => (string) (int) floor($next / 10000),
            '&exppct&' => \sprintf('%01.1f', $span > 0 ? (1 - $next / $span) * 100 : 0.0),
            '&exppct2&' => \sprintf('%01.1f', $span > 0 ? ($next / $span) * 100 : 0.0),
            '&expgain&' => (string) $this->levels->experiencePerPost($posts, $days),
            '&expgaintime&' => \sprintf('%01.3f', $this->levels->experiencePerSecond($posts, $days) ?? 0.0),
            // Rendered in the *author's* zone, since it is their layout.
            '&date&' => $user->toLocalTime($at)->format('m-d-y h:i A'),
            '&rank&' => $this->ranks->resolveLabel($user, $posts),
            // Milestone countdowns, kept for parity with the original's fixed set.
            '&5000&' => (string) (5000 - $posts),
            '&20000&' => (string) (20000 - $posts),
            '&30000&' => (string) (30000 - $posts),
        ];

        // The four ranking tokens differ only by the handicap applied to the user's
        // own count, so one query per distinct handicap covers all of them.
        foreach ([0 => '&postrank&', 10000 => '&postrank10k&', 20000 => '&postrank20k&', 30000 => '&postrank30k&'] as $handicap => $token) {
            $values[$token] = (string) $this->rankWithHandicap($posts, $handicap);
        }

        return $values;
    }

    /**
     * Substitutes token values into a layout body.
     *
     * @param array<string, string> $values
     */
    public function apply(?string $layout, array $values, ?User $author = null): string
    {
        if (null === $layout || '' === $layout) {
            return '';
        }

        // "/me " at the start of a post becomes an emote line, as in IRC.
        if (null !== $author && str_starts_with(ltrim($layout), '/me ')) {
            $layout = preg_replace(
                '~^\s*/me ~',
                '*<b>'.htmlspecialchars($author->getUsername(), \ENT_QUOTES, 'UTF-8').'</b> ',
                $layout,
                1,
            ) ?? $layout;
        }

        // Nothing to do when the body has no tokens at all - the common case.
        if (!str_contains($layout, '&')) {
            return $layout;
        }

        return strtr($layout, $values);
    }

    /**
     * Position in the post-count table, optionally giving the subject a handicap so
     * the "&postrank10k&" style tokens can show where they *would* stand.
     */
    private function rankWithHandicap(int $posts, int $handicap): int
    {
        return 1 + $this->users->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.posts > :threshold')
            ->setParameter('threshold', $posts - $handicap)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
