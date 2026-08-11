<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserPictureRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** An avatar from the board's curated gallery (userpic.php). */
#[ORM\Entity(repositoryClass: UserPictureRepository::class)]
#[ORM\Table(name: 'user_pictures')]
#[ORM\Index(name: 'idx_user_picture_category', columns: ['category_id'])]
class UserPicture
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: UserPictureCategory::class, inversedBy: 'pictures')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private UserPictureCategory $category;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $url = '';

    #[ORM\Column(length: 100)]
    #[Assert\Length(max: 100)]
    private string $name = '';

    public function __construct(UserPictureCategory $category, string $url, string $name = '')
    {
        $this->category = $category;
        $this->url = $url;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): UserPictureCategory
    {
        return $this->category;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

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
}
