<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MessageFolder;
use App\Repository\PrivateMessageFolderRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A user-created message folder. `number` is the value written into
 * PrivateMessage::$recipientFolder, and must stay clear of the reserved 0/1/2 used
 * by Deleted/Inbox/Sent - hence MessageFolder::FIRST_USER_FOLDER.
 */
#[ORM\Entity(repositoryClass: PrivateMessageFolderRepository::class)]
#[ORM\Table(name: 'private_message_folders')]
#[ORM\UniqueConstraint(name: 'uniq_pm_folder_number', columns: ['user_id', 'number'])]
class PrivateMessageFolder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    #[Assert\GreaterThanOrEqual(MessageFolder::FIRST_USER_FOLDER)]
    private int $number;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Please name the folder.')]
    #[Assert\Length(max: 100)]
    private string $name = '';

    public function __construct(User $user, int $number, string $name)
    {
        $this->user = $user;
        $this->number = $number;
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getNumber(): int
    {
        return $this->number;
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

    public function __toString(): string
    {
        return $this->name;
    }
}
