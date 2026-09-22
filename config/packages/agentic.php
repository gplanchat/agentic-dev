<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('agentic', [
        // Vide : un client scripté répond, sans réseau ni clé.
        'mistral_api_key' => '%env(default::MISTRAL_API_KEY)%',
        'watch_subjects' => [
            'commande.expediee' => 'une commande a quitté l’entrepôt',
            'paiement.recu' => 'un paiement a été encaissé',
            'fournisseur.a_repondu' => 'un fournisseur a répondu à une demande',
        ],
    ]);
};
