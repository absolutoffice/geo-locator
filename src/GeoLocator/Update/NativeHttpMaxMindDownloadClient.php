<?php

declare(strict_types=1);

namespace GeoLocator\Update;

use AbsolutOffice\XCurl\Client\HttpClient;
use AbsolutOffice\XCurl\HttpTransportFactory;
use DateTimeImmutable;
use GeoLocator\Exception\ConfigurationException;
use GeoLocator\Update\Contract\MaxMindDownloadClientInterface;
use RuntimeException;

/**
 * Preserves the legacy convenience constructor while delegating networking to xCurl.
 *
 * Transport capabilities select one xCurl backend. xCurl owns its normal redirect
 * and retry policy; this wrapper does not implement backend failover.
 *
 * @deprecated Use MaxMindHttpDownloadClient with an injected xCurl HttpClient.
 */
final class NativeHttpMaxMindDownloadClient implements MaxMindDownloadClientInterface
{
    /**
     * Provider-specific adapter responsible for metadata and verified archive downloads.
     */
    private readonly MaxMindHttpDownloadClient $client;

    /**
     * Creates an xCurl client with its default policy and wraps the MaxMind adapter.
     *
     * @param string $accountId MaxMind account identifier used for HTTP Basic authentication.
     * @param string $licenseKey MaxMind license key used as the HTTP Basic password.
     * @param string $editionId MaxMind database edition used to build archive and checksum URLs.
     * @param int $timeoutSeconds Positive transport timeout in seconds, not a total update deadline.
     * @throws ConfigurationException When xCurl is unavailable or no supported HTTPS transport exists.
     * @throws \GeoLocator\Exception\UpdateException When credentials are blank or the timeout is invalid.
     */
    public function __construct(
        string $accountId,
        string $licenseKey,
        string $editionId = 'GeoLite2-City',
        int $timeoutSeconds = 30,
    ) {
        if (!class_exists(HttpClient::class)) {
            throw new ConfigurationException(
                'The MaxMind updater requires absolutoffice/xcurl. Run composer install to load its dependencies.',
            );
        }

        try {
            $httpClient = new HttpClient(HttpTransportFactory::create());
        } catch (RuntimeException $exception) {
            throw new ConfigurationException(
                'MaxMind updater requires an available xCurl HTTPS transport (cURL, HTTPS streams or TLS sockets).',
                0,
                $exception,
            );
        }

        $this->client = new MaxMindHttpDownloadClient(
            accountId: $accountId,
            licenseKey: $licenseKey,
            client: $httpClient,
            editionId: $editionId,
            timeoutSeconds: $timeoutSeconds,
        );
    }

    /**
     * Delegates the remote Last-Modified check to the MaxMind adapter.
     *
     * @return DateTimeImmutable|null Advertised release time, or null when the header is absent or blank.
     * @throws \GeoLocator\Exception\UpdateException When the HTTP request or timestamp parsing fails.
     */
    public function latestReleaseAt(): ?DateTimeImmutable
    {
        return $this->client->latestReleaseAt();
    }

    /**
     * Downloads and verifies an archive through the delegated MaxMind adapter.
     *
     * @param string $targetPath Unused file path inside a directory controlled by the updater.
     * @return void A successful return leaves the integrity-checked archive at the target.
     * @throws \GeoLocator\Exception\UpdateException When download, filesystem or integrity validation fails.
     */
    public function downloadTo(string $targetPath): void
    {
        $this->client->downloadTo($targetPath);
    }
}
