<?php

declare(strict_types=1);

namespace App\Domain\TrainingPlanner;

final readonly class TrainingSessionLibrarySynchronizer
{
    public function __construct(
        private DbalTrainingSessionRepository $trainingSessionRepository,
    ) {
    }

    public function sync(PlannedSession $plannedSession): void
    {
        $existingTrainingSession = $this->trainingSessionRepository->findBySourcePlannedSessionId($plannedSession->getId());
        $candidateTrainingSession = TrainingSession::createFromPlannedSession(
            plannedSession: $plannedSession,
            existingTrainingSession: $existingTrainingSession,
        );
        $duplicateTrainingSessions = $this->trainingSessionRepository->findDuplicatesOf(
            $candidateTrainingSession,
            $existingTrainingSession?->getId(),
        );

        if ([] === $duplicateTrainingSessions) {
            $this->trainingSessionRepository->upsert($candidateTrainingSession);

            return;
        }

        $primaryDuplicateTrainingSession = array_shift($duplicateTrainingSessions);

        if (null !== $existingTrainingSession && $existingTrainingSession->getId() !== $primaryDuplicateTrainingSession->getId()) {
            $this->trainingSessionRepository->deleteById($existingTrainingSession->getId());
        }

        foreach ($duplicateTrainingSessions as $duplicateTrainingSession) {
            $this->trainingSessionRepository->deleteById($duplicateTrainingSession->getId());
        }

        $this->trainingSessionRepository->upsert(
            $candidateTrainingSession->withPersistedIdentity($primaryDuplicateTrainingSession, true),
        );
    }
}