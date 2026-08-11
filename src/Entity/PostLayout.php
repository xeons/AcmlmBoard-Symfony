<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PostLayoutRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Content-addressed store of post headers and signatures.
 *
 * A user's signature is identical across all of their posts, so storing it inline
 * would repeat the same few kilobytes thousands of times. The original had the same
 * idea (getpostlayoutid() in lib/function.php) but looked rows up with
 * `WHERE text='...'` against an unindexed TEXT column, which is a full scan per post.
 * Here the sha-256 of the body is the lookup key and carries a unique index.
 */
#[ORM\Entity(repositoryClass: PostLayoutRepository::class)]
#[ORM\Table(name: 'post_layouts')]
#[ORM\UniqueConstraint(name: 'uniq_post_layout_hash', columns: ['body_hash'])]
class PostLayout
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(length: 64)]
    private string $bodyHash;

    public function __construct(string $body)
    {
        $this->body = $body;
        $this->bodyHash = self::hash($body);
    }

    public static function hash(string $body): string
    {
        return hash('sha256', $body);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getBodyHash(): string
    {
        return $this->bodyHash;
    }
}
