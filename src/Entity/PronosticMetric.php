<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'pronostic_metric')]
#[ORM\UniqueConstraint(name: 'uniq_pronostic_metric_snapshot_type', columns: ['snapshot_id', 'metric_type'])]
#[ORM\Index(name: 'idx_pronostic_metric_type_calculated', columns: ['metric_type', 'calculated_at'])]
class PronosticMetric
{
    public const TYPE_TOP1_ACCURACY = 'top1_accuracy';
    public const TYPE_TOP3_HIT_RATE = 'top3_hit_rate';
    public const TYPE_MEAN_RANK_ERROR = 'mean_rank_error';
    public const TYPE_NDCG_AT_5 = 'ndcg_at_5';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'metrics')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PronosticSnapshot $snapshot = null;

    #[ORM\Column(name: 'metric_type', length: 50)]
    private string $metricType;

    #[ORM\Column(name: 'metric_value', type: 'float')]
    private float $metricValue;

    #[ORM\Column(name: 'calculated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $calculatedAt;

    public function __construct()
    {
        $this->calculatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSnapshot(): ?PronosticSnapshot
    {
        return $this->snapshot;
    }

    public function setSnapshot(?PronosticSnapshot $snapshot): self
    {
        $this->snapshot = $snapshot;

        return $this;
    }

    public function getMetricType(): string
    {
        return $this->metricType;
    }

    public function setMetricType(string $metricType): self
    {
        $this->metricType = trim($metricType);

        return $this;
    }

    public function getMetricValue(): float
    {
        return $this->metricValue;
    }

    public function setMetricValue(float $metricValue): self
    {
        $this->metricValue = $metricValue;

        return $this;
    }

    public function getCalculatedAt(): \DateTimeImmutable
    {
        return $this->calculatedAt;
    }

    public function setCalculatedAt(\DateTimeImmutable $calculatedAt): self
    {
        $this->calculatedAt = $calculatedAt;

        return $this;
    }
}

