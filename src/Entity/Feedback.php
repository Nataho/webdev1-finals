<?php

namespace App\Entity;

use App\Repository\FeedbackRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer testimonial curated by staff/admin and shown in the home page
 * feedback section. The section only renders while rows exist.
 */
#[ORM\Entity(repositoryClass: FeedbackRepository::class)]
#[ORM\Table(name: 'feedback')]
final class Feedback
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'feedback_id')]
    private ?int $id = null;

    #[ORM\Column(name: 'customer_name', length: 120)]
    private ?string $name = null;

    // the quote itself; the template wraps it in quotation marks
    #[ORM\Column(name: 'message', type: Types::TEXT)]
    private ?string $message = null;

    #[ORM\Column(name: 'role_label', length: 60, options: ['default' => 'Customer'])]
    private string $roleLabel = 'Customer';

    #[ORM\Column(name: 'avatar', length: 255, nullable: true)]
    private ?string $avatar = null;

    /**
     * Read-only creation stamp — Doctrine never writes this column, so the
     * table's CURRENT_TIMESTAMP default keeps timestamping every new row.
     */
    #[ORM\Column(name: 'created_at', insertable: false, updatable: false, nullable: true)]
    private ?\DateTime $createdAt = null;

    /**
     * Computed, never persisted — controllers decorate it with the resolved
     * avatar path (uploads → null, template falls back to the placeholder),
     * exactly like Product#imageUrl.
     */
    private ?string $avatarUrl = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getRoleLabel(): string
    {
        return $this->roleLabel;
    }

    public function setRoleLabel(string $roleLabel): static
    {
        $this->roleLabel = $roleLabel;

        return $this;
    }

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setAvatar(?string $avatar): static
    {
        $this->avatar = $avatar;

        return $this;
    }

    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function setAvatarUrl(?string $avatarUrl): static
    {
        $this->avatarUrl = $avatarUrl;

        return $this;
    }
}
