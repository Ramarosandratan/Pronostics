<?php

namespace App\Controller;

use App\Entity\ImportError;
use App\Entity\ImportSession;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/gestion/imports')]
class ImportManagementController extends AbstractController
{
    #[Route('', name: 'app_management_import_sessions', methods: ['GET'])]
    public function sessions(EntityManagerInterface $entityManager): Response
    {
        $sessions = [];

        try {
            $sessions = $entityManager->getRepository(ImportSession::class)->findBy([], ['importedAt' => 'DESC'], 100);
        } catch (\Throwable $exception) {
            if (!$this->isMissingTableException($exception)) {
                throw $exception;
            }

            $this->addFlash('error', 'Tables import absentes: lancez les migrations Doctrine pour afficher les sessions d\'import.');
        }

        return $this->render('manage/import_sessions.html.twig', [
            'sessions' => $sessions,
        ]);
    }

    #[Route('/{id}', name: 'app_management_import_session_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(ImportSession $session, EntityManagerInterface $entityManager): Response
    {
        $errors = [];

        try {
            $errors = $entityManager->getRepository(ImportError::class)->findBy(
                ['session' => $session],
                ['rowNumber' => 'ASC', 'id' => 'ASC'],
                500
            );
        } catch (\Throwable $exception) {
            if (!$this->isMissingTableException($exception)) {
                throw $exception;
            }

            $this->addFlash('error', 'Table import_error absente: lancez les migrations Doctrine.');
        }

        return $this->render('manage/import_session_show.html.twig', [
            'session' => $session,
            'errors' => $errors,
        ]);
    }

    private function isMissingTableException(\Throwable $exception): bool
    {
        if ($exception instanceof TableNotFoundException) {
            return true;
        }

        return str_contains(strtolower($exception->getMessage()), 'relation')
            && str_contains(strtolower($exception->getMessage()), 'does not exist');
    }
}
