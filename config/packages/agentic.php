<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('agentic', [
        // Vide : un client scripté répond, sans réseau ni clé.
        'mistral_api_key' => '%env(default::MISTRAL_API_KEY)%',
        // Les hooks de décision, avant le mode : deny > ask > allow, motifs fnmatch sur l'outil et
        // ses arguments. Par exemple :
        //   ['tool' => 'send_email', 'when' => ['to' => '*@example.test'], 'decision' => 'allow'],
        //   ['tool' => 'send_email', 'when' => ['to' => '*@concurrent.test'], 'decision' => 'deny', 'reason' => 'Jamais aux concurrents.'],
        'tool_rules' => [],
        // Les consignes du projet, ajoutées au prompt système de chaque nouvelle conversation.
        // 'instructions_file' => '%kernel.project_dir%/AGENTS.md',
        'watch_subjects' => [
            'commande.expediee' => 'une commande a quitté l’entrepôt',
            'paiement.recu' => 'un paiement a été encaissé',
            'fournisseur.a_repondu' => 'un fournisseur a répondu à une demande',
        ],
    ]);
};
