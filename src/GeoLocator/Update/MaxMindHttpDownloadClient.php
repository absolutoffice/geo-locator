<?php

declare(strict_types=1);

namespace GeoLocator\Update;

use AbsolutOffice\XCurl\Client\HttpClient;
use AbsolutOffice\XCurl\Client\HttpClientException;
use AbsolutOffice\XCurl\Client\RequestOptions;
use AbsolutOffice\XCurl\HttpRequest;
use AbsolutOffice\XCurl\HttpResponse;
use AbsolutOffice\XCurl\HttpTransportException;
use DateTimeImmutable;
use GeoLocator\Exception\UpdateException;
use GeoLocator\Update\Contract\MaxMindDownloadClientInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Downloads and verifies MaxMind archives through the xCurl policy client.
 *
 * xCurl owns HTTPS transport, redirects and transient retries. This adapter owns
 * MaxMind authentication, release metadata and at most one archive redownload after
 * an integrity mismatch. It requires an updater-controlled staging directory; the
 * file identity checks do not provide synchronization against hostile directory writers.
 */
final class MaxMindHttpDownloadClient implements MaxMindDownloadClientInterface
{
    /**
     * Initial trusted MaxMind host used to construct archive and checksum request URLs.
     */
    private const DOWNLOAD_HOST = 'download.maxmind.com';
    /**
     * Maximum complete archive attempts: the initial download and one integrity redownload.
     */
    private const MAX_INTEGRITY_DOWNLOADS = 2;

    /**
     * Initial archive URL for the configured edition, before xCurl follows any redirect.
     */
    private readonly string $downloadUrl;
    /**
     * Initial SHA-256 sidecar URL for the same edition as the archive.
     */
    private readonly string $checksumUrl;

    /**
     * Configures MaxMind credentials and endpoints around an injected xCurl policy client.
     *
     * No request is made during construction. The timeout applies to transport work;
     * redirects and retries can make a complete operation exceed that duration.
     *
     * @param string $accountId MaxMind account identifier used as the HTTP Basic username.
     * @param string $licenseKey MaxMind license key used as the HTTP Basic password.
     * @param HttpClient $client Client with its redirect and transient retry policies already configured.
     * @param string $editionId MaxMind edition identifier encoded as a URL path segment.
     * @param int $timeoutSeconds Positive per-transport safety timeout, in seconds.
     * @throws UpdateException When either credential is blank or the timeout is below one second.
     */
    public function __construct(
        /**
         * MaxMind HTTP Basic account identifier; credentials are added only to initial service requests.
         */
        private readonly string $accountId,
        /**
         * Secret MaxMind license key used for initial service authentication.
         */
        private readonly string $licenseKey,
        /**
         * xCurl policy client that handles redirects and transient retries around the selected transport.
         */
        private readonly HttpClient $client,
        string $editionId = 'GeoLite2-City',
        /**
         * Positive transport timeout in seconds; this is not a whole-operation deadline.
         */
        private readonly int $timeoutSeconds = 30,
    ) {
        if (trim($accountId) === '' || trim($licenseKey) === '') {
            throw new UpdateException('MaxMind account ID and license key are required for updates.');
        }
        if ($timeoutSeconds < 1) {
            throw new UpdateException('MaxMind HTTP timeout must be at least one second.');
        }

        $baseUrl = sprintf(
            'https://%s/geoip/databases/%s/download?suffix=',
            self::DOWNLOAD_HOST,
            rawurlencode($editionId),
        );
        $this->downloadUrl = $baseUrl . 'tar.gz';
        $this->checksumUrl = $baseUrl . 'tar.gz.sha256';
    }

    /**
     * Reads the release timestamp using HEAD without downloading the archive body.
     *
     * @return DateTimeImmutable|null Parsed Last-Modified value, or null when the header is missing or blank.
     * @throws UpdateException When the request fails, its final status is not 2xx or the timestamp cannot be parsed.
     */
    public function latestReleaseAt(): ?DateTimeImmutable
    {
        $response = $this->request('HEAD', $this->downloadUrl);
        $this->assertSuccessfulResponse($response);
        $value = $response->header('last-modified');
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            throw new UpdateException('MaxMind returned an invalid Last-Modified header.', 0, $exception);
        }
    }

    /**
     * Downloads to an unused path and verifies the archive before returning.
     *
     * The target must be in a directory controlled by the updater. Existing files,
     * directories and symlinks are rejected before the request. xCurl cleans failed
     * transport attempts; this adapter removes a completed failed archive only after
     * checking the recorded regular-file identity. A missing or malformed checksum,
     * empty archive, byte-count mismatch or digest mismatch allows one redownload.
     * HTTP failures follow xCurl retry policy and do not initiate this integrity retry.
     *
     * @param string $targetPath Unused archive path inside a directory controlled by the updater.
     * @return void A successful return leaves a non-empty archive with a verified SHA-256 digest at the target.
     * @throws UpdateException When HTTP, target ownership, filesystem or integrity validation fails.
     */
    public function downloadTo(string $targetPath): void
    {
        foreach (range(1, self::MAX_INTEGRITY_DOWNLOADS) as $integrityAttempt) {
            $this->assertTargetAvailable($targetPath);
            $response = $this->request('GET', $this->downloadUrl, $targetPath);
            $identity = $this->ownedTargetIdentity($targetPath, $response);
            $accepted = false;

            try {
                $this->assertSuccessfulResponse($response);

                if ($identity === null || $response->bytes < 1) {
                    $failure = 'MaxMind download completed without a usable archive.';
                } elseif (!$this->contentLengthMatches($response)) {
                    $failure = 'MaxMind archive size does not match Content-Length after redownload.';
                } else {
                    $expectedSha256 = $this->downloadExpectedSha256();
                    $this->assertOwnedTargetUnchanged($targetPath, $identity);
                    $actualSha256 = @hash_file('sha256', $targetPath);
                    if (
                        $expectedSha256 !== null
                        && is_string($actualSha256)
                        && hash_equals($expectedSha256, strtolower($actualSha256))
                    ) {
                        $accepted = true;
                        return;
                    }

                    $failure = $expectedSha256 === null
                        ? 'MaxMind SHA-256 checksum response remained invalid after redownload.'
                        : 'MaxMind archive SHA-256 verification failed after redownload.';
                }
            } finally {
                if (!$accepted) {
                    $this->removeOwnedTarget($targetPath, $identity);
                }
            }

            if ($integrityAttempt === self::MAX_INTEGRITY_DOWNLOADS) {
                throw new UpdateException($failure);
            }
        }
    }

    /**
     * Reads the published SHA-256 digest for the selected MaxMind edition.
     *
     * @return string|null Lowercase hexadecimal digest extracted from the sidecar, or null when none is present.
     * @throws UpdateException When the checksum request fails or its final HTTP status is not 2xx.
     */
    private function downloadExpectedSha256(): ?string
    {
        $response = $this->request('GET', $this->checksumUrl);
        $this->assertSuccessfulResponse($response);
        if (preg_match('/\b([a-f0-9]{64})\b/i', $response->body, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    /**
     * Compares a usable Content-Length value with the number of streamed response bytes.
     *
     * Absent, blank or non-decimal header values do not prevent SHA-256 validation and
     * are treated as having no usable byte-count constraint.
     *
     * @param HttpResponse $response Completed archive response with headers and the downloaded byte count.
     * @return bool True when the header is absent or unusable, or when its value equals the received byte count.
     */
    private function contentLengthMatches(HttpResponse $response): bool
    {
        $contentLength = $response->header('content-length');
        if ($contentLength === null) {
            return true;
        }

        $contentLength = trim($contentLength);
        if ($contentLength === '' || !ctype_digit($contentLength)) {
            return true;
        }

        return (int) $contentLength === $response->bytes;
    }

    /**
     * Starts one MaxMind operation while delegating generic HTTP policy to xCurl.
     *
     * Credentials are sent to the initial MaxMind URL. xCurl's redirect policy removes
     * them on a cross-origin redirect and retries the current URL. Public messages omit
     * request history and signed URLs. The original exception remains available through
     * getPrevious() for trusted diagnostics and must be filtered before logging.
     *
     * @param 'GET'|'HEAD' $method Request method used for metadata or archive operations.
     * @param string $url Initial MaxMind archive or checksum URL.
     * @param string|null $targetPath Unused stream destination, or null to keep the response body in memory.
     * @return HttpResponse Final xCurl response, including non-2xx statuses that the caller must reject.
     * @throws UpdateException When xCurl fails or the request or target configuration is invalid.
     */
    private function request(string $method, string $url, ?string $targetPath = null): HttpResponse
    {
        try {
            $result = $this->client->send(
                new HttpRequest($method, $url, [
                    'User-Agent: GeoLocator-MaxMindUpdater/1',
                    'Accept: application/gzip, application/octet-stream, text/plain, */*',
                    'Connection: close',
                    'Authorization: Basic ' . base64_encode($this->accountId . ':' . $this->licenseKey),
                ]),
                new RequestOptions(timeoutSeconds: $this->timeoutSeconds, targetPath: $targetPath),
            );
        } catch (HttpClientException $exception) {
            $reason = $exception->error->value;
            $previous = $exception->getPrevious();
            if ($previous instanceof HttpTransportException) {
                $reason .= '/' . $previous->error->value;
            }
            throw new UpdateException(sprintf('MaxMind HTTP request failed (%s).', $reason), 0, $exception);
        } catch (InvalidArgumentException $exception) {
            throw new UpdateException('MaxMind HTTP request or download target is invalid.', 0, $exception);
        }

        return $result->response;
    }

    /**
     * Rejects any final response outside the HTTP success status range.
     *
     * @param HttpResponse $response Final response after xCurl has handled redirects and transient retries.
     * @return void No value is returned when the response status is between 200 and 299.
     * @throws UpdateException When the final status is outside the 2xx range.
     */
    private function assertSuccessfulResponse(HttpResponse $response): void
    {
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new UpdateException(sprintf(
                'MaxMind download service returned HTTP %d.',
                $response->statusCode,
            ));
        }
    }

    /**
     * Rejects invalid target names and filesystem entries already occupying the target.
     *
     * This is a preflight check; exclusive file creation is performed by xCurl. Callers
     * must keep the parent directory under updater control throughout the operation.
     *
     * @param string $targetPath Candidate archive path to check before a download attempt.
     * @return void No value is returned when the path is non-empty, NUL-free and currently unoccupied.
     * @throws UpdateException When the path is invalid or a file, directory or symlink already exists there.
     */
    private function assertTargetAvailable(string $targetPath): void
    {
        if ($targetPath === '' || str_contains($targetPath, "\0")) {
            throw new UpdateException('MaxMind download target path is invalid.');
        }
        clearstatcache(true, $targetPath);
        if (file_exists($targetPath) || is_link($targetPath)) {
            throw new UpdateException('MaxMind download target already exists.');
        }
    }

    /**
     * Records only a regular file explicitly reported as created by xCurl.
     *
     * @param string $targetPath Path supplied as the download target for this request.
     * @param HttpResponse $response Completed response whose targetCreated flag establishes request ownership.
     * @return array{dev: int, ino: int}|null File device and inode, or null when no target file is present.
     * @throws UpdateException When an existing target is unowned or the created target is no longer a regular file.
     */
    private function ownedTargetIdentity(string $targetPath, HttpResponse $response): ?array
    {
        clearstatcache(true, $targetPath);
        $stat = @lstat($targetPath);
        if (!$response->targetCreated) {
            if ($stat !== false) {
                throw new UpdateException('MaxMind download target was not created by this request.');
            }
            return null;
        }
        if ($stat === false) {
            return null;
        }
        if (($stat['mode'] & 0170000) !== 0100000) {
            throw new UpdateException('MaxMind download target is no longer a regular file.');
        }

        return ['dev' => $stat['dev'], 'ino' => $stat['ino']];
    }

    /**
     * Checks that the target is still a regular file with its recorded device and inode.
     *
     * The check detects observed replacement; it is not an atomic filesystem lock.
     *
     * @param string $targetPath Owned archive path to inspect before hashing or cleanup.
     * @param array{dev: int, ino: int} $identity Identity recorded after the completed download.
     * @return void No value is returned when the current regular file matches the recorded identity.
     * @throws UpdateException When the target disappeared, changed type or has a different device or inode.
     */
    private function assertOwnedTargetUnchanged(string $targetPath, array $identity): void
    {
        clearstatcache(true, $targetPath);
        $stat = @lstat($targetPath);
        if (
            $stat === false
            || ($stat['mode'] & 0170000) !== 0100000
            || $stat['dev'] !== $identity['dev']
            || $stat['ino'] !== $identity['ino']
        ) {
            throw new UpdateException('MaxMind download target changed during verification.');
        }
    }

    /**
     * Removes a completed failed archive after checking the identity recorded for this attempt.
     *
     * No action is taken without recorded ownership or when the target has disappeared.
     * A changed target is preserved and reported as an error rather than being removed.
     *
     * @param string $targetPath Archive target to clean after an unsuccessful integrity attempt.
     * @param array{dev: int, ino: int}|null $identity Recorded device and inode, or null when no file was owned.
     * @return void No value is returned when cleanup succeeds or no owned file remains.
     * @throws UpdateException When the target changed identity or removing the owned file fails.
     */
    private function removeOwnedTarget(string $targetPath, ?array $identity): void
    {
        if ($identity === null) {
            return;
        }
        clearstatcache(true, $targetPath);
        if (@lstat($targetPath) === false) {
            return;
        }
        $this->assertOwnedTargetUnchanged($targetPath, $identity);
        if (!@unlink($targetPath)) {
            throw new UpdateException(
                'Unable to remove the MaxMind download after a failed request or integrity check.',
            );
        }
    }
}
