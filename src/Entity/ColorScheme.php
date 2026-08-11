<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ColorSchemeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A selectable board skin.
 *
 * The original stored a PHP filename in `schemes.file` and require()d it, so adding
 * a scheme meant shipping executable code and a row pointing at it - an arbitrary
 * include if the column was ever writable. A scheme is a slug here, resolved against
 * a stylesheet in public/css/schemes/, and the slug is validated so it can only ever
 * name a file inside that directory.
 */
#[ORM\Entity(repositoryClass: ColorSchemeRepository::class)]
#[ORM\Table(name: 'color_schemes')]
#[ORM\UniqueConstraint(name: 'uniq_color_scheme_slug', columns: ['slug'])]
class ColorScheme
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9-]+$/', message: 'Slugs may only contain lowercase letters, digits and hyphens.')]
    private string $slug = '';

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /**
     * The "dailycycle" scheme interpolated between four palettes according to the
     * time of day. That is done in CSS now, but it still needs marking so the
     * renderer can emit the extra time-of-day class.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $timeCycling = false;

    /**
     * The banner across the top of the page.
     *
     * The original called this $boardtitle and set it per scheme: most inherited
     * images/title2.jpg from colors.php, a handful overrode it, and "classic" set
     * styled text instead of an image. Null here means the same thing it did there -
     * render the board's name as text rather than a picture.
     *
     * Only a path under /images/ is accepted, so a scheme row can never point the
     * banner at another site or at a javascript: URL.
     */
    /*
     * Directory segments are matched without dots, so ".." cannot appear as one.
     * A single character class of [A-Za-z0-9._/-] looks equivalent and is not: it
     * happily accepts /images/../../secret.png, because it permits dots and slashes
     * in any arrangement.
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Regex(
        pattern: '~^/images/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9][A-Za-z0-9._-]*\.(?:png|jpe?g|gif|webp)$~',
        message: 'The banner must be an image path under /images/.',
    )]
    private ?string $titleImage = null;

    public function __construct(string $slug = '', string $name = '')
    {
        $this->slug = $slug;
        $this->name = $name;
    }

    public function getStylesheetPath(): string
    {
        return 'css/schemes/'.$this->slug.'.css';
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

    public function isTimeCycling(): bool
    {
        return $this->timeCycling;
    }

    public function setTimeCycling(bool $timeCycling): static
    {
        $this->timeCycling = $timeCycling;

        return $this;
    }

    public function getTitleImage(): ?string
    {
        return $this->titleImage;
    }

    public function setTitleImage(?string $titleImage): static
    {
        $this->titleImage = '' === $titleImage ? null : $titleImage;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
