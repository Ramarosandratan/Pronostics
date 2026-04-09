<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'person_alias')]
#[ORM\UniqueConstraint(name: 'uniq_person_alias_canonical', columns: ['canonical_form'])]
class PersonAlias
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Person $person;

    #[ORM\Column(name: 'original_form', length: 255)]
    private string $originalForm;

    #[ORM\Column(name: 'canonical_form', length: 255)]
    private string $canonicalForm;

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

    public function getPerson(): Person
    {
        return $this->person;
    }

    public function setPerson(Person $person): self
    {
        $this->person = $person;

        return $this;
    }

    public function getOriginalForm(): string
    {
        return $this->originalForm;
    }

    public function setOriginalForm(string $originalForm): self
    {
        $this->originalForm = trim($originalForm);

        return $this;
    }

    public function getCanonicalForm(): string
    {
        return $this->canonicalForm;
    }

    public function setCanonicalForm(string $canonicalForm): self
    {
        $this->canonicalForm = trim($canonicalForm);

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
