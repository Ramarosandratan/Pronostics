<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'pronostic_snapshot')]
#[ORM\UniqueConstraint(name: 'uniq_pronostic_snapshot_race_type', columns: ['race_id', 'snapshot_type'])]
#[ORM\Index(name: 'idx_pronostic_snapshot_created', columns: ['created_at'])]
class PronosticSnapshot
{
    public const TYPE_PRE_RACE = 'pre_race';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_COMPARED = 'compared';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Race $race;

    #[ORM\Column(name: 'snapshot_type', length: 30)]
    private string $snapshotType = self::TYPE_PRE_RACE;

    #[ORM\Column(name: 'comparison_status', length: 20)]
    private string $comparisonStatus = self::STATUS_PENDING;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'compared_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $comparedAt = null;

    #[ORM\Column(name: 'total_entries')]
    private int $totalEntries = 0;

    #[ORM\Column(name: 'comparable_entries')]
    private int $comparableEntries = 0;

    /**
     * @var Collection<int, PronosticPrediction>
     */
    #[ORM\OneToMany(mappedBy: 'snapshot', targetEntity: PronosticPrediction::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $predictions;

    /**
     * @var Collection<int, PronosticMetric>
     */
    #[ORM\OneToMany(mappedBy: 'snapshot', targetEntity: PronosticMetric::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $metrics;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->predictions = new ArrayCollection();
        $this->metrics = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRace(): Race
    {
        return $this->race;
    }

    public function setRace(Race $race): self
    {
        $this->race = $race;

        return $this;
    }

    public function getSnapshotType(): string
    {
        return $this->snapshotType;
    }

    public function setSnapshotType(string $snapshotType): self
    {
        $this->snapshotType = trim($snapshotType);

        return $this;
    }

    public function getComparisonStatus(): string
    {
        return $this->comparisonStatus;
    }

    public function setComparisonStatus(string $comparisonStatus): self
    {
        $this->comparisonStatus = trim($comparisonStatus);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getComparedAt(): ?\DateTimeImmutable
    {
        return $this->comparedAt;
    }

    public function setComparedAt(?\DateTimeImmutable $comparedAt): self
    {
        $this->comparedAt = $comparedAt;

        return $this;
    }

    public function getTotalEntries(): int
    {
        return $this->totalEntries;
    }

    public function setTotalEntries(int $totalEntries): self
    {
        $this->totalEntries = max(0, $totalEntries);

        return $this;
    }

    public function getComparableEntries(): int
    {
        return $this->comparableEntries;
    }

    public function setComparableEntries(int $comparableEntries): self
    {
        $this->comparableEntries = max(0, $comparableEntries);

        return $this;
    }

    /**
     * @return Collection<int, PronosticPrediction>
     */
    public function getPredictions(): Collection
    {
        return $this->predictions;
    }

    public function addPrediction(PronosticPrediction $prediction): self
    {
        if (!$this->predictions->contains($prediction)) {
            $this->predictions->add($prediction);
            $prediction->setSnapshot($this);
        }

        return $this;
    }

    public function removePrediction(PronosticPrediction $prediction): self
    {
        if ($this->predictions->removeElement($prediction) && $prediction->getSnapshot() === $this) {
            $prediction->setSnapshot(null);
        }

        return $this;
    }

    public function clearPredictions(): self
    {
        foreach ($this->predictions as $prediction) {
            $prediction->setSnapshot(null);
        }

        $this->predictions->clear();

        return $this;
    }

    /**
     * @return Collection<int, PronosticMetric>
     */
    public function getMetrics(): Collection
    {
        return $this->metrics;
    }

    public function addMetric(PronosticMetric $metric): self
    {
        if (!$this->metrics->contains($metric)) {
            $this->metrics->add($metric);
            $metric->setSnapshot($this);
        }

        return $this;
    }

    public function removeMetric(PronosticMetric $metric): self
    {
        if ($this->metrics->removeElement($metric) && $metric->getSnapshot() === $this) {
            $metric->setSnapshot(null);
        }

        return $this;
    }

    public function clearMetrics(): self
    {
        foreach ($this->metrics as $metric) {
            $metric->setSnapshot(null);
        }

        $this->metrics->clear();

        return $this;
    }
}
