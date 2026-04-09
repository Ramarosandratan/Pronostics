<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'import_session')]
class ImportSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'file_name', length: 255)]
    private string $fileName;

    #[ORM\Column(name: 'imported_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $importedAt;

    #[ORM\Column(length: 30)]
    private string $status = 'completed';

    #[ORM\Column(name: 'total_rows')]
    private int $totalRows = 0;

    #[ORM\Column(name: 'rows_imported')]
    private int $rowsImported = 0;

    #[ORM\Column(name: 'rows_skipped')]
    private int $rowsSkipped = 0;

    #[ORM\Column(name: 'error_count')]
    private int $errorCount = 0;

    /**
     * @var Collection<int, ImportError>
     */
    #[ORM\OneToMany(mappedBy: 'session', targetEntity: ImportError::class, orphanRemoval: true)]
    private Collection $errors;

    public function __construct()
    {
        $this->importedAt = new \DateTimeImmutable();
        $this->errors = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function setFileName(string $fileName): self
    {
        $this->fileName = trim($fileName);

        return $this;
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function setImportedAt(\DateTimeImmutable $importedAt): self
    {
        $this->importedAt = $importedAt;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = trim($status);

        return $this;
    }

    public function getTotalRows(): int
    {
        return $this->totalRows;
    }

    public function setTotalRows(int $totalRows): self
    {
        $this->totalRows = $totalRows;

        return $this;
    }

    public function getRowsImported(): int
    {
        return $this->rowsImported;
    }

    public function setRowsImported(int $rowsImported): self
    {
        $this->rowsImported = $rowsImported;

        return $this;
    }

    public function getRowsSkipped(): int
    {
        return $this->rowsSkipped;
    }

    public function setRowsSkipped(int $rowsSkipped): self
    {
        $this->rowsSkipped = $rowsSkipped;

        return $this;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    public function setErrorCount(int $errorCount): self
    {
        $this->errorCount = $errorCount;

        return $this;
    }

    /**
     * @return Collection<int, ImportError>
     */
    public function getErrors(): Collection
    {
        return $this->errors;
    }

    public function addError(ImportError $error): self
    {
        if (!$this->errors->contains($error)) {
            $this->errors->add($error);
            $error->setSession($this);
        }

        return $this;
    }

    public function removeError(ImportError $error): self
    {
        if ($this->errors->removeElement($error) && $error->getSession() === $this) {
            $error->setSession(null);
        }

        return $this;
    }
}
