<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\BoardConfig;
use App\Entity\BoardStats;
use App\Entity\Category;
use App\Entity\ColorScheme;
use App\Entity\Forum;
use App\Entity\Item;
use App\Entity\ItemCategory;
use App\Entity\Rank;
use App\Entity\RankSet;
use App\Entity\ThreadLayout;
use App\Entity\UserPicture;
use App\Entity\UserPictureCategory;
use App\Service\RankLadderCatalog;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reference data a working board needs: colour schemes, post layouts, rank ladders,
 * the shop catalogue, and a starter forum structure.
 *
 * In the original all of this lived in the SQL dump as INSERT statements, which meant
 * the CSS files under schemes/ and the rows in the `schemes` table had to be kept in
 * step by hand - and were not: several rows pointed at files that no longer existed.
 * The scheme list is read from config/schemes.json, the same file the stylesheet
 * generator writes, so the two cannot drift.
 */
final class BoardFixtures extends Fixture
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly RankLadderCatalog $ranks,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $this->loadColorSchemes($manager);
        $this->loadThreadLayouts($manager);
        $this->loadRankSets($manager);
        $this->loadShop($manager);
        $this->loadAvatars($manager);
        $forums = $this->loadForums($manager);

        $manager->flush();

        $this->loadConfig($manager, $forums);

        $manager->flush();
    }

    private function loadColorSchemes(ObjectManager $manager): void
    {
        $file = $this->projectDir.'/config/schemes.json';
        $definitions = is_file($file)
            ? (json_decode((string) file_get_contents($file), true) ?: [])
            : [];

        // Fall back to a single scheme rather than leaving the board unstyled.
        if ([] === $definitions) {
            $definitions = [['slug' => 'classic', 'name' => 'Classic', 'position' => 0, 'timeCycling' => false]];
        }

        foreach ($definitions as $definition) {
            $scheme = new ColorScheme($definition['slug'], $definition['name']);
            $scheme->setPosition((int) ($definition['position'] ?? 0));
            $scheme->setTimeCycling((bool) ($definition['timeCycling'] ?? false));
            // Null is meaningful: it renders the board name as text, which is what
            // the "classic" scheme did instead of showing a banner image.
            $scheme->setTitleImage($definition['titleImage'] ?? null);
            $manager->persist($scheme);
        }
    }

    private function loadThreadLayouts(ObjectManager $manager): void
    {
        // Each slug must have a matching templates/post/layout/{slug}.html.twig.
        $layouts = [
            'regular' => 'Regular',
            'compact' => 'Compact',
            'extended' => 'Extended',
            'vertical' => 'Vertical',
            'ubb' => 'UBB style',
            'vbb' => 'vBulletin style',
            'ezboard' => 'ezboard style',
            'rpg' => 'RPG',
            'postwide' => 'Wide',
        ];

        $position = 0;
        foreach ($layouts as $slug => $name) {
            $layout = new ThreadLayout($slug, $name);
            $layout->setPosition($position++);
            $manager->persist($layout);
        }
    }

    /**
     * The rank ladders, from config/ranks.json.
     *
     * These used to be three short hand-written ladders of plain text. The real
     * ones are in the original's SQL dump: 206 rungs across a Super Mario set, a
     * Zelda set and the "Global ranking" set, and nearly every rung is a sprite
     * stacked over its name. The sprites were copied across with the rest of
     * public/images and are checked to exist when the JSON is generated.
     *
     * "Global ranking" was rank set 3, whose top nine thresholds updategb()
     * rewrote on every page load by walking the whole users table. Those rungs
     * carry the percentile they represent and RankPercentileUpdater recomputes
     * their thresholds on a schedule; until it has run they sit at an unreachable
     * sentinel so a board too small to rank does not award the top badge to
     * everyone.
     */
    private function loadRankSets(ObjectManager $manager): void
    {
        foreach ($this->ranks->sets() as $definition) {
            $set = new RankSet($definition['name']);
            $set->setPosition($definition['position']);
            $set->setPercentileBased($definition['percentileBased']);
            $manager->persist($set);

            foreach ($definition['ranks'] as $rung) {
                $rank = new Rank($set, $rung['minPosts'], $rung['label']);
                $rank->setPercentile($rung['percentile'] ?? null);
                $manager->persist($rank);
            }
        }
    }

    private function loadShop(ObjectManager $manager): void
    {
        // Six equipment slots, matching the original's users_rpg.eq1..eq6.
        $slots = [
            ['Weapon shop', 'Swords, staves and other implements of debate.', ['Atk' => 8, 'Dex' => 2]],
            ['Armour shop', 'Protection from flame wars.', ['Def' => 8, 'Spd' => -1]],
            ['Shield shop', 'For deflecting hot takes.', ['MDf' => 7, 'Spd' => -1]],
            ['Helmet shop', 'Headgear of questionable utility.', ['Def' => 4, 'Int' => 3]],
            ['Accessory shop', 'Rings, charms and lucky trinkets.', ['Lck' => 6, 'Int' => 2]],
            ['Relic shop', 'Rare items with outsized effects.', ['HP' => 20, 'MP' => 10]],
        ];

        $tiers = [
            ['Bronze', 1, 250],
            ['Iron', 2, 1200],
            ['Silver', 3, 4000],
            ['Gold', 5, 14000],
            ['Mythril', 8, 45000],
        ];

        $position = 0;
        foreach ($slots as [$name, $description, $profile]) {
            $category = new ItemCategory($name, $description);
            $category->setPosition($position++);
            $manager->persist($category);

            $itemPosition = 0;
            foreach ($tiers as [$tier, $multiplier, $price]) {
                $item = new Item();
                $item->setCategory($category);
                $item->setName($tier.' '.strtolower(str_replace(' shop', '', $name)));
                $item->setPrice($price);
                $item->setPosition($itemPosition++);

                $stats = [];
                $modes = [];
                foreach ($profile as $stat => $value) {
                    $stats[$stat] = $value * $multiplier;
                    $modes[$stat] = Item::MODE_ADD;
                }
                $item->setStats($stats);
                $item->setStatModes($modes);

                $manager->persist($item);
            }
        }
    }

    /**
     * The avatar gallery, from config/avatars.json.
     *
     * This used to seed three invented rows pointing at /images/avatars/mario.png and
     * friends - paths that never existed, so choosing an avatar gave every member a
     * broken image. The real gallery was in the original board's SQL dump the whole
     * time, and its 292 image files were copied across with the rest of public/images.
     * The JSON is generated from that dump and every entry is checked to exist.
     */
    private function loadAvatars(ObjectManager $manager): void
    {
        $file = $this->projectDir.'/config/avatars.json';
        $definitions = is_file($file)
            ? (json_decode((string) file_get_contents($file), true) ?: [])
            : [];

        foreach ($definitions as $definition) {
            $category = new UserPictureCategory($definition['name']);
            $category->setPage((int) ($definition['page'] ?? 0));
            $manager->persist($category);

            foreach ($definition['pictures'] ?? [] as $picture) {
                $manager->persist(new UserPicture($category, $picture['url'], $picture['name'] ?? ''));
            }
        }
    }

    /** @return array{trash: Forum, staff: Forum} */
    private function loadForums(ObjectManager $manager): array
    {
        $general = new Category();
        $general->setName('General');
        $general->setPosition(0);
        $manager->persist($general);

        $staffCategory = new Category();
        $staffCategory->setName('Staff');
        $staffCategory->setPosition(90);
        // Only staff (power >= 1) can see this category or anything in it.
        $staffCategory->setMinPower(1);
        $manager->persist($staffCategory);

        $archive = new Category();
        $archive->setName('Archive');
        $archive->setPosition(99);
        $manager->persist($archive);

        $make = static function (
            Category $category,
            string $title,
            string $description,
            int $position,
            int $minPower = 0,
        ) use ($manager): Forum {
            $forum = new Forum();
            $forum->setCategory($category);
            $forum->setTitle($title);
            $forum->setDescription($description);
            $forum->setPosition($position);
            $forum->setMinPower($minPower);
            $manager->persist($forum);

            return $forum;
        };

        $make($general, 'Announcements', 'Board news and administrative notices.', 0);
        $make($general, 'General discussion', 'Anything that does not fit elsewhere.', 1);
        $make($general, 'Introductions', 'New here? Say hello.', 2);

        $staff = $make($staffCategory, 'Staff discussion', 'Private staff forum.', 0, 1);
        $make($staffCategory, 'Disciplinary records', 'Per-member staff comment threads.', 1, 1);

        $trash = $make($archive, 'Trash', 'Threads removed by moderators.', 0, 1);
        $trash->setTrash(true);

        return ['trash' => $trash, 'staff' => $staff];
    }

    /** @param array{trash: Forum, staff: Forum} $forums */
    private function loadConfig(ObjectManager $manager, array $forums): void
    {
        $config = new BoardConfig();
        $config->setBoardName("Acmlm's Board");
        $config->setTrashForum($forums['trash']);
        $config->setDisciplinaryForum($forums['staff']);
        $manager->persist($config);

        $manager->persist(new BoardStats());
    }
}
