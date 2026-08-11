<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** A heading on the index page that groups forums. */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Table(name: 'categories')]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    /** Minimum power level required to see this category at all. */
    #[ORM\Column(options: ['default' => 0])]
    private int $minPower = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** @var Collection<int, Forum> */
    #[ORM\OneToMany(targetEntity: Forum::class, mappedBy: 'category')]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $forums;

    public function __construct()
    {
        $this->forums = new ArrayCollection();
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

    public function getMinPower(): int
    {
        return $this->minPower;
    }

    public function setMinPower(int $minPower): static
    {
        $this->minPower = $minPower;

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

    /** @return Collection<int, Forum> */
    public function getForums(): Collection
    {
        return $this->forums;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
