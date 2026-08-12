<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Service\LevelCalculator;
use App\Service\MarkupRenderer;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Presentation helpers shared by every template.
 *
 * Anything returning HTML is declared with `is_safe: html` and produces only markup
 * this class built itself, or output that has already been through the sanitizer.
 * User-supplied text is escaped here rather than relying on the caller to add |e -
 * the original's failure mode was exactly the reverse.
 */
final class BoardExtension extends AbstractExtension
{
    public function __construct(
        private readonly MarkupRenderer $markup,
        private readonly LevelCalculator $levels,
        private readonly Security $security,
        private readonly \App\Service\SmileyRepository $smileys,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('board_markup', $this->renderMarkup(...), ['is_safe' => ['html']]),
            new TwigFilter('board_inline', $this->renderInline(...), ['is_safe' => ['html']]),
            new TwigFilter('board_rank', $this->renderRank(...), ['is_safe' => ['html']]),
            new TwigFilter('board_plain', $this->markup->toPlainText(...)),
            new TwigFilter('board_date', $this->formatDate(...)),
            new TwigFilter('board_date_short', $this->formatDateShort(...)),
            new TwigFilter('duration', $this->formatDuration(...)),
            new TwigFilter('duration_long', $this->formatDurationLong(...)),
            new TwigFilter('since', $this->formatSince(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('user_link', $this->userLink(...), ['is_safe' => ['html']]),
            new TwigFunction('name_class', $this->nameClass(...)),
            new TwigFunction('user_experience', $this->experience(...)),
            new TwigFunction('user_level', $this->level(...)),
            new TwigFunction('level_progress', $this->levelProgress(...)),
            new TwigFunction('experience_to_next', $this->experienceToNext(...)),
            new TwigFunction('safe_image_url', $this->safeImageUrl(...)),
            new TwigFunction('smileys', $this->smileys->all(...)),
        ];
    }

    public function renderMarkup(?string $raw): string
    {
        return $this->markup->render($raw);
    }

    public function renderInline(?string $raw): string
    {
        return $this->markup->renderInline($raw);
    }

    public function renderRank(?string $raw): string
    {
        return $this->markup->renderRank($raw);
    }

    /**
     * Renders an instant in the *viewer's* timezone, which is what every timestamp
     * on the original board did via the `$tzoff` global - except that a named zone
     * carries its own daylight-saving rules, so this stays correct year-round.
     *
     * Accepts a string ("now", "2026-01-01") as well as a date object, so templates
     * can write `'now'|board_date` without constructing one first. Reaching for
     * Twig's built-in `|date` instead is the easy mistake: it formats in PHP's
     * default zone and silently shows every member the server's clock.
     */
    public function formatDate(\DateTimeInterface|string|null $date, string $format = 'm-d-y h:i A'): string
    {
        if (null === $date || '' === $date) {
            return '-------- --:-- --';
        }

        if (\is_string($date)) {
            try {
                $date = new \DateTimeImmutable($date);
            } catch (\Exception) {
                return '-------- --:-- --';
            }
        }

        $immutable = $date instanceof \DateTimeImmutable
            ? $date
            : \DateTimeImmutable::createFromInterface($date);

        $viewer = $this->security->getUser();
        if ($viewer instanceof User) {
            $immutable = $viewer->toLocalTime($immutable);
        }

        return $immutable->format($format);
    }

    public function formatDateShort(?\DateTimeInterface $date): string
    {
        return $this->formatDate($date, 'm-d-y');
    }

    /**
     * Coarse elapsed time: "5 sec.", "12 min.", "3 hours", "9 days".
     * Reproduces timeunits() from lib/function.php.
     */
    public function formatDuration(?\DateTimeInterface $since, ?\DateTimeInterface $now = null): string
    {
        if (null === $since) {
            return 'never';
        }

        $seconds = max(0, ($now?->getTimestamp() ?? time()) - $since->getTimestamp());

        return match (true) {
            $seconds < 60 => $seconds.' sec.',
            $seconds < 3600 => intdiv($seconds, 60).' min.',
            $seconds < 7200 => '1 hour',
            $seconds < 86400 => intdiv($seconds, 3600).' hours',
            $seconds < 172800 => '1 day',
            default => intdiv($seconds, 86400).' days',
        };
    }

    /** Full breakdown: "2 days 3 hours 15 min. 9 sec." - the original's timeunits2(). */
    public function formatDurationLong(?\DateTimeInterface $since, ?\DateTimeInterface $now = null): string
    {
        if (null === $since) {
            return 'never';
        }

        $seconds = max(0, ($now?->getTimestamp() ?? time()) - $since->getTimestamp());

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds, 3600) % 24;
        $minutes = intdiv($seconds, 60) % 60;
        $secs = $seconds % 60;

        $parts = [];
        if ($days) {
            $parts[] = $days.' day'.($days > 1 ? 's' : '');
        }
        if ($hours) {
            $parts[] = $hours.' hour'.($hours > 1 ? 's' : '');
        }
        if ($minutes) {
            $parts[] = $minutes.' min.';
        }
        if ($secs || [] === $parts) {
            $parts[] = $secs.' sec.';
        }

        return implode(' ', $parts);
    }

    public function formatSince(?\DateTimeInterface $date): string
    {
        return $this->formatDuration($date);
    }

    /**
     * The username link, coloured by sex and power level.
     *
     * The original built this inline in nine different files as
     * `<a href=profile.php?id=$id><font $namecolor>$name</font></a>`, with the name
     * unescaped every time - so a username containing markup broke out into the
     * page on the index, the member list, the online list and every post header.
     */
    public function userLink(?User $user, bool $withMiniPic = false): string
    {
        if (null === $user) {
            return '<span class="name name-deleted">(deleted)</span>';
        }

        $name = htmlspecialchars($user->getUsername(), \ENT_QUOTES, 'UTF-8');
        $href = '/profile/'.$user->getId();
        $class = 'name '.$this->nameClass($user);

        $pic = '';
        if ($withMiniPic && null !== $url = $this->safeImageUrl($user->getMiniPic())) {
            $pic = '<img class="minipic" width="11" height="11" alt="" src="'
                .htmlspecialchars($url, \ENT_QUOTES, 'UTF-8').'"> ';
        }

        return '<span class="user-link">'.$pic.'<a class="'.$class.'" href="'.$href.'">'.$name.'</a></span>';
    }

    /** CSS class encoding sex and power level, replacing the $nmcol lookup table. */
    public function nameClass(?User $user): string
    {
        if (null === $user) {
            return 'name-deleted';
        }

        return $user->getSex()->cssClass().' power-'.str_replace('-', 'neg', (string) $user->getPowerLevel()->value);
    }

    public function experience(?User $user): int
    {
        return null === $user ? 0 : $this->levels->experienceFor($user);
    }

    public function level(?User $user): int
    {
        return null === $user ? 0 : $this->levels->levelFor($user);
    }

    public function levelProgress(?User $user): float
    {
        return null === $user ? 0.0 : $this->levels->levelProgress($this->levels->experienceFor($user));
    }

    public function experienceToNext(?User $user): int
    {
        return null === $user ? 0 : $this->levels->experienceToNextLevel($this->levels->experienceFor($user));
    }

    /**
     * Validates a user-supplied image URL before it reaches a src attribute.
     * Returns null when it is not a plain http(s) URL, so templates can fall back
     * rather than emit an attribute they cannot vouch for.
     */
    public function safeImageUrl(?string $url): ?string
    {
        if (null === $url || '' === trim($url)) {
            return null;
        }

        $url = trim($url);

        if (preg_match('~^https?://[^\s<>"\']+$~i', $url)) {
            return $url;
        }

        // An image served by this board, which is what the avatar gallery stores.
        // Accepting only absolute URLs meant a member who picked an avatar from the
        // board's own gallery got no picture at all: the value was rejected here and
        // silently rendered as nothing.
        return preg_match(User::LOCAL_IMAGE_PATTERN, $url) ? $url : null;
    }
}
