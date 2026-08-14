<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Job\Handlers;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\WittyBundle\Service\Apollo\ApolloClient;
use MauticPlugin\WittyBundle\Service\Job\Handlers\ApolloBulkEnrichPeopleJobHandler;
use PHPUnit\Framework\TestCase;

/**
 * Pas de couverture complete de processChunk() ici (DQL contre lead_lists_leads
 * deja verifie contre une vraie base MySQL locale en session, cf. README) :
 * uniquement le point qui a motive l'ajout d'allowsMultiplePassesPerTick() —
 * un handler qui appelle Apollo ne doit jamais etre enchaine plusieurs fois
 * dans le meme passage de cron (limite de debit du fournisseur).
 */
class ApolloBulkEnrichPeopleJobHandlerTest extends TestCase
{
    public function testDoesNotAllowMultiplePassesPerTick(): void
    {
        $handler = new ApolloBulkEnrichPeopleJobHandler($this->createMock(ApolloClient::class), $this->createMock(EntityManagerInterface::class));

        $this->assertFalse($handler->allowsMultiplePassesPerTick());
    }
}
