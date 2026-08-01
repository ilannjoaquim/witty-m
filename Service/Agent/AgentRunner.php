<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Agent;

use MauticPlugin\WittyBundle\Service\Llm\Dto\Message;
use MauticPlugin\WittyBundle\Service\Llm\Exception\LlmException;
use MauticPlugin\WittyBundle\Service\Llm\ProviderFactory;
use MauticPlugin\WittyBundle\Service\Tool\ToolRegistry;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Psr\Log\LoggerInterface;

/**
 * Boucle de l agent : appel du modele, execution des outils demandes,
 * re-injection des resultats, jusqu a obtenir une reponse textuelle.
 */
class AgentRunner
{
    public function __construct(
        private ProviderFactory $providerFactory,
        private ToolRegistry $toolRegistry,
        private PromptBuilder $promptBuilder,
        private WittyConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $serializedHistory transcript renvoye par le navigateur
     *
     * @return array{reply: string, history: array<int, array<string, mixed>>, trace: array<int, array<string, mixed>>}
     */
    public function run(array $serializedHistory, string $userMessage): array
    {
        if (!$this->config->isConfigured()) {
            throw new LlmException("Aucune cle API configuree. Renseigne-la dans Parametres > Configuration > Witty.");
        }

        $messages = array_map(
            static fn (array $item): Message => Message::fromArray($item),
            $serializedHistory,
        );

        $messages[] = Message::user($userMessage);

        $provider    = $this->providerFactory->get();
        $definitions = $this->toolRegistry->getDefinitions();
        $systemPrompt = $this->promptBuilder->build();
        $model       = $this->config->getModel();
        $apiKey      = $this->config->getApiKey();

        $trace = [];
        $reply = '';

        for ($iteration = 0; $iteration < $this->config->getMaxIterations(); ++$iteration) {
            $result = $provider->chat($messages, $definitions, $systemPrompt, $model, $apiKey);

            $messages[] = Message::assistant($result->text, $result->toolCalls);

            if (!$result->hasToolCalls()) {
                $reply = (string) $result->text;
                break;
            }

            foreach ($result->toolCalls as $call) {
                $this->logger->info('Witty tool call', ['tool' => $call->name, 'arguments' => $call->arguments]);

                $output = $this->toolRegistry->execute($call->name, $call->arguments);

                $trace[] = [
                    'tool'   => $call->name,
                    'status' => (string) ($output['status'] ?? 'ok'),
                    'label'  => $this->label($call->name, $output),
                ];

                $messages[] = Message::toolResult($call, $output);
            }

            // Un texte intermediaire accompagnant des appels d outils reste utile a afficher.
            if (null !== $result->text && '' !== $result->text) {
                $reply = $result->text;
            }
        }

        if ('' === $reply) {
            $reply = "J ai atteint la limite d iterations sans conclure. Reformule ou decoupe la demande.";
        }

        return [
            'reply'   => $reply,
            'history' => array_map(static fn (Message $m): array => $m->toArray(), $messages),
            'trace'   => $trace,
        ];
    }

    /**
     * @param array<string, mixed> $output
     */
    private function label(string $tool, array $output): string
    {
        return match ((string) ($output['status'] ?? '')) {
            'error'                 => sprintf('%s : echec', $tool),
            'confirmation_required' => sprintf('%s : confirmation demandee', $tool),
            default                 => isset($output['id'])
                ? sprintf('%s : #%s cree', $tool, (string) $output['id'])
                : $tool,
        };
    }
}
