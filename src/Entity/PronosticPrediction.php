<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'pronostic_prediction')]
#[ORM\UniqueConstraint(name: 'uniq_pronostic_prediction_snapshot_participation', columns: ['snapshot_id', 'participation_id'])]
#[ORM\Index(name: 'idx_pronostic_prediction_snapshot_rank', columns: ['snapshot_id', 'predicted_rank'])]
class PronosticPrediction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'predictions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PronosticSnapshot $snapshot = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Participation $participation;

    #[ORM\Column(name: 'predicted_rank')]
    private int $predictedRank;

    #[ORM\Column(name: 'predicted_score', type: 'float')]
    private float $predictedScore;

    #[ORM\Column(name: 'sub_scores', type: 'json', nullable: true)]
    private ?array $subScores = null;

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

    public function getParticipation(): Participation
    {
        return $this->participation;
    }

    public function setParticipation(Participation $participation): self
    {
        $this->participation = $participation;

        return $this;
    }

    public function getPredictedRank(): int
    {
        return $this->predictedRank;
    }

    public function setPredictedRank(int $predictedRank): self
    {
        $this->predictedRank = $predictedRank;

        return $this;
    }

    public function getPredictedScore(): float
    {
        return $this->predictedScore;
    }

    public function setPredictedScore(float $predictedScore): self
    {
        $this->predictedScore = $predictedScore;

        return $this;
    }

    /**
     * @return array<string, float>|null
     */
    public function getSubScores(): ?array
    {
        return $this->subScores;
    }

    /**
     * @param array<string, float>|null $subScores
     */
    public function setSubScores(?array $subScores): self
    {
        $this->subScores = $subScores;

        return $this;
    }
}
