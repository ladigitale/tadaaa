<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Publisher / host identity for legal pages — values from env only (never hardcode PII in git).
 */
final class LegalConfig
{
    public function __construct(
        #[Autowire('%env(string:LEGAL_PUBLISHER_NAME)%')]
        private readonly string $publisherName,
        #[Autowire('%env(string:LEGAL_PUBLISHER_EMAIL)%')]
        private readonly string $publisherEmail,
        #[Autowire('%env(string:LEGAL_PUBLISHER_ADDRESS)%')]
        private readonly string $publisherAddress,
        #[Autowire('%env(string:LEGAL_SIRET)%')]
        private readonly string $siret,
        #[Autowire('%env(string:LEGAL_HOST_NAME)%')]
        private readonly string $hostName,
        #[Autowire('%env(string:LEGAL_HOST_ADDRESS)%')]
        private readonly string $hostAddress,
        #[Autowire('%env(string:LEGAL_HOST_CONTACT)%')]
        private readonly string $hostContact,
        #[Autowire('%env(string:LEGAL_PRIVACY_EMAIL)%')]
        private readonly string $privacyEmail,
    ) {
    }

    /**
     * @return array{
     *   configured: bool,
     *   publisherName: string,
     *   publisherEmail: string,
     *   publisherAddress: string,
     *   siret: string,
     *   hostName: string,
     *   hostAddress: string,
     *   hostContact: string,
     *   privacyEmail: string
     * }
     */
    public function toPublicArray(): array
    {
        $publisherName = trim($this->publisherName);
        $publisherEmail = trim($this->publisherEmail);
        $privacy = trim($this->privacyEmail);
        if ($privacy === '') {
            $privacy = $publisherEmail;
        }
        $hostName = trim($this->hostName);

        return [
            'configured' => $publisherName !== '' && $publisherEmail !== '' && $hostName !== '',
            'publisherName' => $publisherName,
            'publisherEmail' => $publisherEmail,
            'publisherAddress' => trim($this->publisherAddress),
            'siret' => trim($this->siret),
            'hostName' => $hostName,
            'hostAddress' => trim($this->hostAddress),
            'hostContact' => trim($this->hostContact),
            'privacyEmail' => $privacy,
        ];
    }
}
