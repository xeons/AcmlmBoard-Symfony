<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ThreadLayoutRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * How a single post is arranged: regular, compact, vertical, ubb, vbb, ezboard, rpg.
 *
 * The original require()d tlayouts/$file.php, which defined two globals (userfields()
 * and postcode()) and returned an HTML string. Each is a Twig template here, named
 * by slug and resolved to templates/post/layout/{slug}.html.twig.
 */
#[ORM\Entity(repositoryClass: ThreadLayoutRepository::class)]
#[ORM\Table(name: 'thread_layouts')]
#[ORM\UniqueConstraint(name: 'uniq_thread_layout_slug', columns: ['slug'])]
class ThreadLayout
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9-]+$/')]
    private string $slug = '';

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __construct(string $slug = '', string $name = '')
    {
        $this->slug = $slug;
        $this->name = $name;
    }

    public function getTemplate(): string
    {
        return 'post/layout/'.$this->slug.'.html.twig';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

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
