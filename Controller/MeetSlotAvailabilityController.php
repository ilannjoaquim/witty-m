<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use Mautic\FormBundle\Model\FieldModel;
use MauticPlugin\WittyBundle\EventListener\FormSubscriber;
use MauticPlugin\WittyBundle\Service\PlugNmeet\MeetSlotAvailabilityCalculator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Endpoint public (pas de compte Mautic requis, cf. Config/config.php) appele
 * en AJAX par le widget JS du champ "witty.meet_slot_picker"
 * (Resources/views/Field/meet_slot_picker.html.twig) pour peupler un mois du
 * calendrier. Lecture seule : la reservation reelle se fait a la validation
 * du formulaire (EventListener/MeetSlotValidationSubscriber.php), jamais ici.
 */
class MeetSlotAvailabilityController extends CommonController
{
    public function availabilityAction(
        int $fieldId,
        Request $request,
        FieldModel $fieldModel,
        MeetSlotAvailabilityCalculator $calculator,
    ): JsonResponse {
        $field = $fieldModel->getEntity($fieldId);

        if (null === $field || FormSubscriber::SLOT_PICKER_FIELD_TYPE !== $field->getType()) {
            return new JsonResponse(['slots' => []], JsonResponse::HTTP_NOT_FOUND);
        }

        $monthStart = $this->parseMonth($request->query->get('month'));
        $monthEnd   = $monthStart->modify('+1 month');

        $slots = $calculator->computeAvailableSlots($field, $monthStart, $monthEnd);

        return new JsonResponse([
            'month' => $monthStart->format('Y-m'),
            'slots' => array_map(
                static fn (\DateTimeImmutable $slot): string => $slot->format(\DateTimeInterface::ATOM),
                $slots
            ),
        ]);
    }

    // Toute valeur absente ou mal formee retombe silencieusement sur le mois
    // en cours : ce n'est qu'un affichage, pas la peine de renvoyer une
    // erreur 400 pour un widget qui construit lui-meme ce parametre.
    private function parseMonth(?string $month): \DateTimeImmutable
    {
        if (null !== $month && 1 === preg_match('/^(\d{4})-(\d{2})$/', $month, $matches)) {
            $candidate = \DateTimeImmutable::createFromFormat('!Y-m', $matches[1].'-'.$matches[2]);

            if (false !== $candidate) {
                return $candidate;
            }
        }

        return (new \DateTimeImmutable())->modify('first day of this month')->setTime(0, 0);
    }
}
