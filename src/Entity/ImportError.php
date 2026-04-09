<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'import_error')]
class ImportError
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'errors')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ImportSession $session = null;

    #[ORM\Column(name: 'row_number', nullable: true)]
    private ?int $rowNumber = null;

    #[ORM\Column(name: 'error_type', length: 50)]
    private string $errorType;

    #[ORM\Column(length: 20)]
    private string $severity = 'error';

    #[ORM\Column(name: 'error_message', length: 1000)]
    private string $errorMessage;

    #[ORM\Column(name: 'source_snapshot', type: 'json', nullable: true)]
    private ?array $sourceSnapshot = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): ?ImportSession
    {
        return $this->session;
    }

    public function setSession(?ImportSession $session): self
    {
        $this->session = $session;

        return $this;
    }

    public function getRowNumber(): ?int
    {
        return $this->rowNumber;
    }

    public function setRowNumber(?int $rowNumber): self
    {
        $this->rowNumber = $rowNumber;

        return $this;
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }

    public function setErrorType(string $errorType): self
    {
        $this->errorType = trim($errorType);

        return $this;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function setSeverity(string $severity): self
    {
        $this->severity = trim($severity);

        return $this;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(string $errorMessage): self
    {
        $this->errorMessage = trim($errorMessage);

        return $this;
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function getSourceSnapshot(): ?array
    {
        return $this->sourceSnapshot;
    }

    /**
     * @param array<int|string, mixed>|null $sourceSnapshot
     */
    public function setSourceSnapshot(?array $sourceSnapshot): self
    {
        $this->sourceSnapshot = $sourceSnapshot;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
