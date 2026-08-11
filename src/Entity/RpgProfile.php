<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RpgProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's shop state: what they have equipped, and how many coins they have spent.
 *
 * Coins are not stored - they are derived from post count and account age by
 * RpgCalculator::coins(), minus `spent`. That is how the original worked and it
 * means a recount cannot desync someone's wallet.
 */
#[ORM\Entity(repositoryClass: RpgProfileRepository::class)]
#[ORM\Table(name: 'rpg_profiles')]
class RpgProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'rpgProfile')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(options: ['default' => 0])]
    private int $spent = 0;

    /**
     * Equipment slot => item id, keyed by ItemCategory id.
     *
     * @var array<int, int>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $loadout = [];

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getEquippedItemId(int $categoryId): ?int
    {
        return $this->loadout[$categoryId] ?? null;
    }

    public function equip(int $categoryId, int $itemId): static
    {
        $this->loadout[$categoryId] = $itemId;

        return $this;
    }

    public function unequip(int $categoryId): static
    {
        unset($this->loadout[$categoryId]);

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSpent(): int
    {
        return $this->spent;
    }

    public function setSpent(int $spent): static
    {
        $this->spent = $spent;

        return $this;
    }

    public function addSpent(int $delta): static
    {
        $this->spent += $delta;

        return $this;
    }

    /** @return array<int, int> */
    public function getLoadout(): array
    {
        return $this->loadout;
    }

    /** @param array<int, int> $loadout */
    public function setLoadout(array $loadout): static
    {
        $this->loadout = $loadout;

        return $this;
    }
}
