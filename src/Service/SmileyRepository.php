<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The smiley table.
 *
 * The original read smilies.dat on *every* post render - fopen, a loop of fgetcsv
 * with a chr(255) delimiter, fclose - and did so twice, because numsmilies() opened
 * the same file again just to count the rows. It is a JSON file read once per process
 * here, and cached in the container in production.
 */
final class SmileyRepository
{
    /** @var list<array{code: string, image: string}>|null */
    private ?array $smileys = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/smileys.json')]
        private readonly string $file,
    ) {
    }

    /**
     * @return list<array{code: string, image: string}> longest code first, so that
     *                                                  ":-))" is matched before ":-)"
     */
    public function all(): array
    {
        if (null !== $this->smileys) {
            return $this->smileys;
        }

        if (!is_file($this->file)) {
            return $this->smileys = [];
        }

        $decoded = json_decode((string) file_get_contents($this->file), true);
        if (!\is_array($decoded)) {
            return $this->smileys = [];
        }

        $smileys = [];
        foreach ($decoded as $entry) {
            if (isset($entry['code'], $entry['image']) && \is_string($entry['code']) && \is_string($entry['image'])) {
                $smileys[] = ['code' => $entry['code'], 'image' => $entry['image']];
            }
        }

        usort($smileys, static fn (array $a, array $b): int => \strlen($b['code']) <=> \strlen($a['code']));

        return $this->smileys = $smileys;
    }

    public function count(): int
    {
        return \count($this->all());
    }
}
