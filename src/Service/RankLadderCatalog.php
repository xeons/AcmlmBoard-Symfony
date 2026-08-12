<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The shipped rank ladders, read from config/ranks.json.
 *
 * The original kept these as INSERT statements in the SQL dump: three sets and 206
 * rungs, nearly all of them a sprite stacked over a name. Reading them from one
 * file means the fixtures and the sync command cannot disagree about what a
 * stock board's ranks are.
 *
 * @phpstan-type RungDefinition array{minPosts: int, label: string, percentile?: float}
 * @phpstan-type SetDefinition array{name: string, position: int, percentileBased: bool, ranks: list<RungDefinition>}
 */
final class RankLadderCatalog
{
    /** @var list<SetDefinition>|null */
    private ?array $sets = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/ranks.json')]
        private readonly string $file,
    ) {
    }

    /**
     * @return list<SetDefinition>
     */
    public function sets(): array
    {
        if (null !== $this->sets) {
            return $this->sets;
        }

        if (!is_file($this->file)) {
            return $this->sets = [];
        }

        $decoded = json_decode((string) file_get_contents($this->file), true);
        if (!\is_array($decoded)) {
            return $this->sets = [];
        }

        $sets = [];
        foreach ($decoded as $definition) {
            if (!\is_array($definition) || !isset($definition['name'])) {
                continue;
            }

            $rungs = [];
            foreach ($definition['ranks'] ?? [] as $rung) {
                if (!\is_array($rung) || !isset($rung['label'])) {
                    continue;
                }

                $entry = [
                    'minPosts' => (int) ($rung['minPosts'] ?? 0),
                    'label' => (string) $rung['label'],
                ];
                if (isset($rung['percentile'])) {
                    $entry['percentile'] = (float) $rung['percentile'];
                }
                $rungs[] = $entry;
            }

            $sets[] = [
                'name' => (string) $definition['name'],
                'position' => (int) ($definition['position'] ?? 0),
                'percentileBased' => (bool) ($definition['percentileBased'] ?? false),
                'ranks' => $rungs,
            ];
        }

        return $this->sets = $sets;
    }
}
