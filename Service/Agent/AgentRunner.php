<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Agent;

use MauticPlugin\WittyBundle\Entity\WittyConversation;
use MauticPlugin\WittyBundle\Service\Conversation\ConversationManager;
use MauticPlugin\WittyBundle\Service\Llm\Dto\LlmResult;
use MauticPlugin\WittyBundle\Service\Llm\Dto\Message;
use MauticPlugin\WittyBundle\Service\Llm\Dto\Usage;
use MauticPlugin\WittyBundle\Service\Llm\Exception\LlmException;
use MauticPlugin\WittyBundle\Service\Llm\LlmProviderInterface;
use MauticPlugin\WittyBundle\Service\Llm\ProviderFactory;
use MauticPlugin\WittyBundle\Service\Tool\ToolRegistry;
use MauticPlugin\WittyBundle\Service\Usage\UsageGuard;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Psr\Log\LoggerInterface;

/**
 * Boucle de l'agent : appel du modele, execution des outils demandes,
 * re-injection des resultats, jusqu'a obtenir une reponse textuelle.
 *
 * Un seul chemin de code sert les deux modes d'affichage : sans emetteur on
 * attend la reponse complete, avec emetteur les fragments partent au fil de
 * l'eau. Faire diverger les deux serait le meilleur moyen de n'en tester qu'un.
 */
class AgentRunner
{
    /** Evenements passes a l'emetteur. */
    public const EVENT_DELTA = 'delta';
    public const EVENT_TOOL  = 'tool';
    public const EVENT_USAGE = 'usage';

    public function __construct(
        private ProviderFactory $providerFactory,
        private ToolRegistry $toolRegistry,
        private PromptBuilder $promptBuilder,
        private WittyConfig $config,
        private ConversationManager $conversations,
        private UsageGuard $usageGuard,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param callable(string, array<string, mixed>): void|null $emit
     *
     * @return array{reply: string, trace: array<int, array<string, mixed>>, usage: array<string, int>}
     */
    public function run(WittyConversation $conversation, string $userMessage, ?callable $emit = null): array
    {
        if (!$this->config->isConfigured()) {
            throw new LlmException('Aucune cle API configuree. Renseigne-la dans Parametres > Plugins > Witty.');
        }

        $this->usageGuard->assertWithinQuota();

        $this->conversations->append($conversation, Message::user($userMessage));

        $provider     = $this->providerFactory->get();
        $definitions  = $this->toolRegistry->getDefinitions();
        $systemPrompt = $this->promptBuilder->build();
        $model        = $this->config->getModel();
        $apiKey       = $this->config->getApiKey();

        $conversation->setProvider($provider->getKey())->setModel($model);

        $trace = [];
        $reply = '';
        $total = new Usage();

        for ($iteration = 0; $iteration < $this->config->getMaxIterations(); ++$iteration) {
            $messages = $this->conversations->toMessages($conversation);

            $result = $this->call($provider, $messages, $definitions, $systemPrompt, $model, $apiKey, $emit);
            $total  = $total->plus($result->usage);

            $this->conversations->append(
                $conversation,
                Message::assistant($result->text, $result->toolCalls),
                $result->usage,
            );

            if (null !== $emit) {
                $emit(self::EVENT_USAGE, $total->toArray());
            }

            if (!$result->hasToolCalls()) {
                $reply = (string) $result->text;
                break;
            }

            foreach ($result->toolCalls as $call) {
                $this->logger->info('Witty tool call', ['tool' => $call->name, 'arguments' => $call->arguments]);

                if (null !== $emit) {
                    $emit(self::EVENT_TOOL, ['tool' => $call->name, 'state' => 'running']);
                }

                $output = $this->toolRegistry->execute($call->name, $call->arguments, $conversation);

                $entry   = [
                    'tool'   => $call->name,
                    'status' => (string) ($output['status'] ?? 'ok'),
                    'label'  => $this->label($call->name, $output),
                ];
                $trace[] = $entry;

                if (null !== $emit) {
                    $emit(self::EVENT_TOOL, $entry + ['state' => 'done']);
                }

                $this->conversations->append($conversation, Message::toolResult($call, $output));
            }

            // Un texte intermediaire accompagnant des appels d'outils reste utile a afficher.
            if (null !== $result->text && '' !== $result->text) {
                $reply = $result->text;
            }
        }

        if ('' === $reply) {
            $reply = "J'ai atteint la limite d'iterations sans conclure. Reformule ou decoupe la demande.";
        }

        $this->conversations->save($conversation);

        return ['reply' => $reply, 'trace' => $trace, 'usage' => $total->toArray()];
    }

    /**
     * @param Message[]                                                                  $messages
     * @param array<int, array{name: string, description: string, schema: array<mixed>}> $definitions
     * @param callable(string, array<string, mixed>): void|null                          $emit
     */
    private function call(
        LlmProviderInterface $provider,
        array $messages,
        array $definitions,
        string $systemPrompt,
        string $model,
        string $apiKey,
        ?callable $emit,
    ): LlmResult {
        if (null === $emit) {
            return $provider->chat($messages, $definitions, $systemPrompt, $model, $apiKey);
        }

        return $provider->stream(
            $messages,
            $definitions,
            $systemPrompt,
            $model,
            $apiKey,
            static fn (string $chunk) => $emit(self::EVENT_DELTA, ['text' => $chunk]),
        );
    }

    /**
     * @param array<string, mixed> $output
     */
    private function label(string $tool, array $output): string
    {
        return match ((string) ($output['status'] ?? '')) {
            'error'                 => sprintf('%s : echec', $tool),
            'denied'                => sprintf('%s : permission refusee', $tool),
            'confirmation_required' => sprintf('%s : confirmation demandee', $tool),
            default                 => isset($output['id'])
                ? sprintf('%s : #%s', $tool, (string) $output['id'])
                : $tool,
        };
    }
}
