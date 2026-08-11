<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserPictureCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserPictureCategoryRepository::class)]
#[ORM\Table(name: 'user_picture_categories')]
class UserPictureCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    private string $name = '';

    /** Gallery page this category is listed on, preserving `userpiccateg.page`. */
    #[ORM\Column(options: ['default' => 0])]
    private int $page = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** @var Collection<int, UserPicture> */
    #[ORM\OneToMany(targetEntity: UserPicture::class, mappedBy: 'category', orphanRemoval: true)]
    private Collection $pictures;

    public function __construct(string $name = '')
    {
        $this->name = $name;
        $this->pictures = new ArrayCollection();
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

    public function getPage(): int
    {
        return $this->page;
    }

    public function setPage(int $page): static
    {
        $this->page = $page;

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

    /** @return Collection<int, UserPicture> */
    public function getPictures(): Collection
    {
        return $this->pictures;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
