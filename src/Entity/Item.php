<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An equippable item from the RPG shop.
 *
 * The original spread the nine stats across columns sHP, sMP, sAtk ... sSpd and
 * decided per stat whether the number was additive or multiplicative by reading the
 * n-th character of a `stype` string ("mmaaammaa"). Both are modelled explicitly
 * here: one JSON map of stat => value, one of stat => mode.
 */
#[ORM\Entity(repositoryClass: ItemRepository::class)]
#[ORM\Table(name: 'items')]
#[ORM\Index(name: 'idx_item_category', columns: ['category_id'])]
class Item
{
    /** Ordered stat keys; the shop table renders columns in this order. */
    public const STATS = ['HP', 'MP', 'Atk', 'Def', 'Int', 'MDf', 'Dex', 'Lck', 'Spd'];

    public const MODE_ADD = 'add';
    public const MODE_MULTIPLY = 'multiply';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Null means the item appears in every shop (the original's `cat=0` wildcard). */
    #[ORM\ManyToOne(targetEntity: ItemCategory::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ItemCategory $category = null;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 60)]
    private string $name = '';

    /**
     * stat key => numeric modifier. Additive stats are flat bonuses; multiplicative
     * stats are percentages, so 100 means "unchanged".
     *
     * @var array<string, int>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $stats = [];

    /**
     * stat key => self::MODE_ADD | self::MODE_MULTIPLY.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $statModes = [];

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $price = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function getStat(string $stat): int
    {
        return $this->stats[$stat] ?? (self::MODE_MULTIPLY === $this->getStatMode($stat) ? 100 : 0);
    }

    public function getStatMode(string $stat): string
    {
        return $this->statModes[$stat] ?? self::MODE_ADD;
    }

    public function isMultiplicative(string $stat): bool
    {
        return self::MODE_MULTIPLY === $this->getStatMode($stat);
    }

    /** True when this stat leaves the wearer unchanged, so the cell renders blank. */
    public function isStatNeutral(string $stat): bool
    {
        $value = $this->getStat($stat);

        return $this->isMultiplicative($stat) ? 100 === $value : 0 === $value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): ?ItemCategory
    {
        return $this->category;
    }

    public function setCategory(?ItemCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /** @return array<string, int> */
    public function getStats(): array
    {
        return $this->stats;
    }

    /** @param array<string, int> $stats */
    public function setStats(array $stats): static
    {
        $this->stats = $stats;

        return $this;
    }

    /** @return array<string, string> */
    public function getStatModes(): array
    {
        return $this->statModes;
    }

    /** @param array<string, string> $statModes */
    public function setStatModes(array $statModes): static
    {
        $this->statModes = $statModes;

        return $this;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function setPrice(int $price): static
    {
        $this->price = max(0, $price);

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
