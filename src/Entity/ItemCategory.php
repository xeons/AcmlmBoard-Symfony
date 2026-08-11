<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ItemCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An equipment slot and the shop that sells for it. The original tied slot to
 * category by *column name* - `users_rpg.eq1` held the item bought from category 1 -
 * so adding a seventh shop meant an ALTER TABLE. RpgProfile keys its loadout by
 * category id instead.
 */
#[ORM\Entity(repositoryClass: ItemCategoryRepository::class)]
#[ORM\Table(name: 'item_categories')]
class ItemCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(length: 255)]
    private string $description = '';

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** @var Collection<int, Item> */
    #[ORM\OneToMany(targetEntity: Item::class, mappedBy: 'category')]
    #[ORM\OrderBy(['position' => 'ASC', 'price' => 'ASC'])]
    private Collection $items;

    public function __construct(string $name = '', string $description = '')
    {
        $this->name = $name;
        $this->description = $description;
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

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

    /** @return Collection<int, Item> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
