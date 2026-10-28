<?php

/**
 * Exercise the MaxMind adapter through the real xcurl retry and redirect client.
 *
 * Deterministic transports replace network access while asserting HTTP status handling,
 * credential scoping, streaming, integrity redownloads, cleanup and exception causality.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use GeoLocator\Exception\UpdateException;
use AbsolutOffice\XCurl\Contract\HttpTransportInterface;
use AbsolutOffice\XCurl\Contract\SleeperInterface;
use AbsolutOffice\XCurl\HttpResponse;
use AbsolutOffice\XCurl\HttpTransportException;
use AbsolutOffice\XCurl\Client\RetryPolicy;
use AbsolutOffice\XCurl\Client\HttpClient;
use AbsolutOffice\XCurl\Client\HttpClientException;
use GeoLocator\Update\MaxMindHttpDownloadClient;

requireXcurl();

/**
 * Sleeper fixture recording retry delays without slowing the HTTP policy tests.
 */
final class FakeSleeper implements SleeperInterface
{
    /**
     * Requested delays, in milliseconds and call order.
     *
     * @var list<int>
     */
    public array $delays = [];

    /**
     * Record the requested delay without waiting or validating its sign.
     *
     * @param int $milliseconds Delay selected by the real xcurl retry policy.
     * @return void
     */
    public function sleepMilliseconds(int $milliseconds): void
    {
        $this->delays[] = $milliseconds;
    }
}

/**
 * Queued single-request transport fixture used beneath the real xcurl policy client.
 *
 * It records every request and can either return a response, create an exclusive
 * download target, or throw a queued transport failure. Redirects and retries are
 * handled by the injected HttpClient, so their behavior remains observable here.
 */
final class FakeHttpTransport implements HttpTransportInterface
{
    /**
     * Remaining response or failure scenarios, consumed one per transport request.
     *
     * @var list<array{
     *     status?: int,
     *     headers?: list<string>,
     *     body?: string,
     *     file?: string,
     *     exception?: HttpTransportException
     * }>
     */
    private array $queue;

    /**
     * Complete request history used to assert URLs, credential scoping and streaming options.
     *
     * @var list<array{method:string,url:string,headers:list<string>,target:?string,timeout:int,body:?string}>
     */
    public array $requests = [];

    /**
     * Queue the exact response sequence expected from one or more client operations.
     *
     * @param list<array{
     *     status?: int,
     *     headers?: list<string>,
     *     body?: string,
     *     file?: string,
     *     exception?: HttpTransportException
     * }> $queue Ordered response metadata, in-memory bodies, streamed file bytes or exceptions.
     */
    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    /**
     * Record one request and execute the next queued response scenario.
     *
     * @param string $method HTTP method selected by HttpClient.
     * @param string $url Current request URL, including redirects and signed storage URLs.
     * @param list<string> $headers Complete outgoing header lines for credential-scope assertions.
     * @param int $timeoutSeconds Transport timeout passed through by the MaxMind adapter.
     * @param string|null $targetPath Exclusive file target, or null to return an in-memory body.
     * @param string|null $requestBody Optional request body retained in the request history.
     * @return HttpResponse Queued response with accurate byte count and targetCreated ownership flag.
     * @throws HttpTransportException When the next queue entry specifies a transport failure.
     * @throws RuntimeException When the queue is exhausted or the fixture cannot create/write its target.
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        int $timeoutSeconds,
        ?string $targetPath = null,
        ?string $requestBody = null,
    ): HttpResponse {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'target' => $targetPath,
            'timeout' => $timeoutSeconds,
            'body' => $requestBody,
        ];

        $next = array_shift($this->queue);
        if ($next === null) {
            throw new RuntimeException('Fake HTTP queue exhausted.');
        }
        if (isset($next['exception'])) {
            throw $next['exception'];
        }

        $file = $next['file'] ?? '';
        if ($targetPath !== null) {
            $handle = fopen($targetPath, 'xb');
            if ($handle === false) {
                throw new RuntimeException('Unable to create exclusive fake download target.');
            }
            try {
                if (fwrite($handle, $file) !== strlen($file)) {
                    throw new RuntimeException('Unable to write fake download target.');
                }
            } finally {
                fclose($handle);
            }
        }

        return new HttpResponse(
            statusCode: $next['status'] ?? 200,
            headers: $next['headers'] ?? [],
            body: $targetPath === null ? ($next['body'] ?? '') : '',
            bytes: $targetPath !== null ? strlen($file) : strlen($next['body'] ?? ''),
            targetCreated: $targetPath !== null,
        );
    }
}

/**
 * Transport fixture verifying that failed-attempt cleanup belongs to xcurl transports.
 */
final class PartialFailureHttpTransport implements HttpTransportInterface
{
    /**
     * Simulate a partial download, remove its owned file and throw a terminal failure.
     *
     * @param string $method Requested HTTP method; it does not change the simulated failure.
     * @param string $url Requested URL; no network connection is made.
     * @param list<string> $headers Outgoing headers accepted to satisfy the transport contract.
     * @param int $timeoutSeconds Accepted transport timeout; the fixture does not wait.
     * @param string|null $targetPath Disposable target to create and remove, or null for no file operation.
     * @param string|null $requestBody Optional body; ignored by this failure fixture.
     * @return never This fixture always fails after its cleanup step.
     * @throws HttpTransportException For every request, with retryable=false.
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        int $timeoutSeconds,
        ?string $targetPath = null,
        ?string $requestBody = null,
    ): HttpResponse {
        if ($targetPath !== null) {
            file_put_contents($targetPath, 'partial-download');
            // xCurl transports own cleanup of their partial file before throwing.
            unlink($targetPath);
        }

        throw new HttpTransportException('simulated final transport failure', false);
    }
}

/**
 * Detect whether an outgoing header list contains an Authorization field.
 *
 * @param list<string> $headers Complete header lines recorded by the fake transport.
 * @return bool True when any line starts with Authorization:, ignoring letter case.
 */
function hasAuthorization(array $headers): bool
{
    foreach ($headers as $header) {
        if (str_starts_with(strtolower($header), 'authorization:')) {
            return true;
        }
    }
    return false;
}

/**
 * Require a domain-level update failure and expose it for message/causal-chain assertions.
 *
 * @param callable(): mixed $operation Operation expected to throw UpdateException.
 * @param string $message Assertion message used if the operation unexpectedly returns normally.
 * @return UpdateException The captured domain exception, retaining its original previous exception.
 * @throws RuntimeException When the operation does not fail with UpdateException.
 * @throws Throwable When the operation throws a different exception, propagated unchanged.
 */
function expectUpdateFailure(callable $operation, string $message): UpdateException
{
    try {
        $operation();
    } catch (UpdateException $exception) {
        return $exception;
    }

    throw new RuntimeException($message);
}

/**
 * Assemble the real MaxMind/xcurl policy boundary around an offline test transport.
 *
 * @param HttpTransportInterface $transport Fake single-request transport supplying deterministic responses.
 * @param int $maxRetries HTTP retries after the first attempt; zero disables policy retries.
 * @param FakeSleeper|null $sleeper Delay recorder, or null to create a new one.
 * @param int $timeoutSeconds Positive per-transport timeout forwarded to the MaxMind adapter.
 * @return MaxMindHttpDownloadClient Client using fixture credentials and the real retry/redirect policies.
 * @throws InvalidArgumentException When the retry policy configuration is invalid.
 * @throws UpdateException When the MaxMind adapter timeout is invalid.
 */
function makeHttpDownloadClient(
    HttpTransportInterface $transport,
    int $maxRetries = 2,
    ?FakeSleeper $sleeper = null,
    int $timeoutSeconds = 30,
): MaxMindHttpDownloadClient {
    return new MaxMindHttpDownloadClient(
        '123',
        'secret',
        new HttpClient(
            $transport,
            retryPolicy: new RetryPolicy(maxRetries: $maxRetries),
            sleeper: $sleeper ?? new FakeSleeper(),
        ),
        timeoutSeconds: $timeoutSeconds,
    );
}

$retryPolicy = new RetryPolicy(maxRetries: 2);

// Transient HTTP failures retry at 1s and 3s, then succeed.
$sleeper = new FakeSleeper();
$transport = new FakeHttpTransport([
    ['status' => 503],
    ['status' => 503],
    ['status' => 200, 'headers' => ['Last-Modified: Tue, 15 Sep 2026 10:00:00 GMT']],
]);
$client = makeHttpDownloadClient($transport, sleeper: $sleeper);
$release = $client->latestReleaseAt();
expect(
    $release?->getTimestamp() === strtotime('Tue, 15 Sep 2026 10:00:00 GMT'),
    'Transient retry release date mismatch.',
);
expect(count($transport->requests) === 3, 'Transient status must attempt at most three requests.');
expect($sleeper->delays === [1000, 3000], 'Transient backoff must be 1s then 3s.');
expect($transport->requests[0]['method'] === 'HEAD', 'Release discovery must use HEAD.');
expect($transport->requests[0]['target'] === null, 'HEAD must not create a download file.');
expect($transport->requests[0]['body'] === null, 'HEAD must not send a request body.');

// Retry-After is honored (within policy cap).
$sleeper = new FakeSleeper();
$transport = new FakeHttpTransport([
    ['status' => 429, 'headers' => ['Retry-After: 5']],
    ['status' => 200, 'headers' => ['Last-Modified: Tue, 15 Sep 2026 10:00:00 GMT']],
]);
$client = makeHttpDownloadClient($transport, sleeper: $sleeper);
$client->latestReleaseAt();
expect($sleeper->delays === [5000], 'Retry-After must override normal first retry delay.');

// Authentication/configuration errors fail immediately without retry.
$sleeper = new FakeSleeper();
$transport = new FakeHttpTransport([
    ['status' => 401],
]);
$client = makeHttpDownloadClient($transport, sleeper: $sleeper);
try {
    $client->latestReleaseAt();
    throw new RuntimeException('HTTP 401 must fail immediately.');
} catch (UpdateException) {
}
expect(count($transport->requests) === 1, 'HTTP 401 must not retry.');
expect($sleeper->delays === [], 'HTTP 401 must not sleep.');

// A transient transport error is retried.
$sleeper = new FakeSleeper();
$transport = new FakeHttpTransport([
    ['exception' => new HttpTransportException('temporary connect failure', true)],
    ['status' => 200, 'headers' => ['Last-Modified: Tue, 15 Sep 2026 10:00:00 GMT']],
]);
$client = makeHttpDownloadClient($transport, sleeper: $sleeper);
$client->latestReleaseAt();
expect(count($transport->requests) === 2, 'Transient transport failure must retry.');
expect($sleeper->delays === [1000], 'Transient transport failure must apply retry delay.');

// Credentials are sent to MaxMind but never propagated to the redirected R2 host.
$sleeper = new FakeSleeper();
$transport = new FakeHttpTransport([
    ['status' => 302, 'headers' => ['Location: https://storage.example.test/object']],
    ['status' => 200, 'headers' => ['Last-Modified: Tue, 15 Sep 2026 10:00:00 GMT']],
]);
$client = makeHttpDownloadClient($transport, sleeper: $sleeper);
$client->latestReleaseAt();
expect(hasAuthorization($transport->requests[0]['headers']), 'Initial MaxMind request must contain Basic auth.');
expect(
    !hasAuthorization($transport->requests[1]['headers']),
    'Redirected host must never receive MaxMind credentials.',
);

// A final transport failure must not leave a partial download behind.
$tempRoot = sys_get_temp_dir() . '/geolocator-http-' . bin2hex(random_bytes(5));
mkdir($tempRoot, 0777, true);
$target = $tempRoot . '/db.tar.gz';
$client = new MaxMindHttpDownloadClient(
    '123',
    'secret',
    new HttpClient(
        new PartialFailureHttpTransport(),
        retryPolicy: new RetryPolicy(maxRetries: 0),
        sleeper: new FakeSleeper(),
    ),
);
try {
    $client->downloadTo($target);
    throw new RuntimeException('Final transport failure must abort the download.');
} catch (UpdateException) {
}
expect(!is_file($target), 'Final transport failure must remove a partial archive.');

// Valid archive + official SHA-256 passes integrity verification.
$archive = 'valid-archive-payload';
$sha = hash('sha256', $archive);
$transport = new FakeHttpTransport([
    ['status' => 200, 'headers' => ['Content-Length: ' . strlen($archive)], 'file' => $archive],
    ['status' => 200, 'body' => $sha . "  GeoLite2-City_20260915.tar.gz\n"],
]);
$client = new MaxMindHttpDownloadClient(
    '123',
    'secret',
    new HttpClient($transport, retryPolicy: $retryPolicy, sleeper: new FakeSleeper()),
);
$client->downloadTo($target);
expect(file_get_contents($target) === $archive, 'SHA-256 verified archive must remain on disk.');

// A checksum mismatch triggers one complete redownload, then accepts the good copy.
@unlink($target);
$archive2 = 'second-good-payload';
$sha2 = hash('sha256', $archive2);
$transport = new FakeHttpTransport([
    ['status' => 200, 'file' => 'damaged-payload'],
    ['status' => 200, 'body' => str_repeat('0', 64) . "  GeoLite2-City.tar.gz\n"],
    ['status' => 200, 'file' => $archive2],
    ['status' => 200, 'body' => $sha2 . "  GeoLite2-City.tar.gz\n"],
]);
$client = new MaxMindHttpDownloadClient(
    '123',
    'secret',
    new HttpClient($transport, retryPolicy: $retryPolicy, sleeper: new FakeSleeper()),
);
$client->downloadTo($target);
expect(file_get_contents($target) === $archive2, 'Second archive must be accepted when SHA-256 matches.');
expect(count($transport->requests) === 4, 'Checksum mismatch must trigger exactly one complete redownload.');

// Two checksum mismatches fail closed and remove the candidate archive.
@unlink($target);
$transport = new FakeHttpTransport([
    ['status' => 200, 'file' => 'bad-one'],
    ['status' => 200, 'body' => str_repeat('1', 64)],
    ['status' => 200, 'file' => 'bad-two'],
    ['status' => 200, 'body' => str_repeat('2', 64)],
]);
$client = new MaxMindHttpDownloadClient(
    '123',
    'secret',
    new HttpClient($transport, retryPolicy: $retryPolicy, sleeper: new FakeSleeper()),
);
try {
    $client->downloadTo($target);
    throw new RuntimeException('Two SHA-256 mismatches must fail.');
} catch (UpdateException) {
}
expect(!is_file($target), 'Failed integrity verification must remove the archive.');


// A malformed checksum response gets one fresh archive/checksum pair, then fails closed.
@unlink($target);
$transport = new FakeHttpTransport([
    ['status' => 200, 'file' => 'first-copy'],
    ['status' => 200, 'body' => 'not-a-checksum'],
    ['status' => 200, 'file' => 'second-copy'],
    ['status' => 200, 'body' => 'still-not-a-checksum'],
]);
$client = new MaxMindHttpDownloadClient(
    '123',
    'secret',
    new HttpClient($transport, retryPolicy: $retryPolicy, sleeper: new FakeSleeper()),
);
try {
    $client->downloadTo($target);
    throw new RuntimeException('Malformed checksum response must fail after one redownload.');
} catch (UpdateException) {
}
expect(!is_file($target), 'Malformed checksum failure must remove the archive.');

// Content-Length mismatch also causes one redownload before checksum verification.
$archive3 = 'length-correct';
$sha3 = hash('sha256', $archive3);
$transport = new FakeHttpTransport([
    ['status' => 200, 'headers' => ['Content-Length: 999'], 'file' => 'short'],
    ['status' => 200, 'headers' => ['Content-Length: ' . strlen($archive3)], 'file' => $archive3],
    ['status' => 200, 'body' => $sha3],
]);
$client = new MaxMindHttpDownloadClient(
    '123',
    'secret',
    new HttpClient($transport, retryPolicy: $retryPolicy, sleeper: new FakeSleeper()),
);
$client->downloadTo($target);
expect(file_get_contents($target) === $archive3, 'Content-Length mismatch must recover with one redownload.');

@unlink($target);

// xCurl returns final error statuses: the MaxMind adapter must still reject them.
foreach ([401, 503] as $status) {
    $sleeper = new FakeSleeper();
    $queue = $status === 401 ? [['status' => 401]] : array_fill(0, 3, ['status' => 503]);
    $transport = new FakeHttpTransport($queue);
    $client = makeHttpDownloadClient($transport, sleeper: $sleeper);
    $exception = expectUpdateFailure(
        static fn () => $client->downloadTo($target),
        'A final HTTP error status must become UpdateException.',
    );
    expect(str_contains($exception->getMessage(), (string) $status), 'HTTP error must retain its status code.');
    expect(count($transport->requests) === ($status === 401 ? 1 : 3), 'Final-status retry budget mismatch.');
    expect(!is_file($target), 'A final HTTP error must remove the attempt-owned error response file.');
    expect($sleeper->delays === ($status === 401 ? [] : [1000, 3000]), 'Final-status retry delay mismatch.');
}

// A checksum HTTP failure must remove the successful archive owned by this operation.
$transport = new FakeHttpTransport([
    ['status' => 200, 'file' => 'downloaded-archive'],
    ['status' => 401],
]);
$client = makeHttpDownloadClient($transport);
expectUpdateFailure(static fn () => $client->downloadTo($target), 'Checksum HTTP failure must abort.');
expect(!file_exists($target), 'Checksum HTTP failure must remove the owned archive.');

// Retry the current redirected URL without adding an outer MaxMind retry loop.
$signedUrl = 'https://storage.example.test/object?signature=test-only';
$payload = 'redirected-payload';
$transport = new FakeHttpTransport([
    ['status' => 302, 'headers' => ['Location: ' . $signedUrl]],
    ['status' => 503, 'file' => 'temporary-error'],
    ['status' => 200, 'file' => $payload],
    ['status' => 200, 'body' => hash('sha256', $payload)],
]);
$client = makeHttpDownloadClient($transport, timeoutSeconds: 11);
$client->downloadTo($target);
expect(count($transport->requests) === 4, 'Redirect retries must not multiply the request budget.');
expect($transport->requests[1]['url'] === $signedUrl, 'First redirect must use the signed target.');
expect($transport->requests[2]['url'] === $signedUrl, 'Retry must use xCurl current-URL semantics.');
expect(!hasAuthorization($transport->requests[1]['headers']), 'Redirect target must not receive Basic auth.');
expect(!hasAuthorization($transport->requests[2]['headers']), 'Redirect retry must not restore Basic auth.');
expect(hasAuthorization($transport->requests[3]['headers']), 'Checksum request must start with MaxMind auth.');
expect($transport->requests[0]['timeout'] === 11, 'Configured timeout must reach xCurl.');
expect(file_get_contents($target) === $payload, 'Redirect and retry must preserve the valid archive.');
unlink($target);

// A rejected signed URL is not retried as a fresh authenticated MaxMind request.
$transport = new FakeHttpTransport([
    ['status' => 302, 'headers' => ['Location: ' . $signedUrl]],
    ['status' => 403, 'file' => 'expired-signature'],
]);
$client = makeHttpDownloadClient($transport);
$exception = expectUpdateFailure(static fn () => $client->downloadTo($target), 'Signed URL HTTP 403 must fail.');
expect(count($transport->requests) === 2, 'HTTP 403 must not trigger a new retry chain.');
expect(!file_exists($target), 'Rejected signed response must be cleaned up.');
expect(!str_contains($exception->getMessage(), 'signature='), 'Public errors must not include signed URLs.');

// Keep the public message safe and preserve the original cause for private diagnostics.
$transportFailure = new HttpTransportException('Unable to fetch ' . $signedUrl, false);
$transport = new FakeHttpTransport([
    ['status' => 302, 'headers' => ['Location: ' . $signedUrl]],
    ['exception' => $transportFailure],
]);
$client = makeHttpDownloadClient($transport);
$exception = expectUpdateFailure(static fn () => $client->latestReleaseAt(), 'Transport failure must be translated.');
expect(!str_contains($exception->getMessage(), 'signature='), 'Transport error must redact the signed URL.');
$clientFailure = $exception->getPrevious();
expect($clientFailure instanceof HttpClientException, 'UpdateException must preserve the xCurl client cause.');
expect(
    $clientFailure->getPrevious() === $transportFailure,
    'Private diagnostics must retain the original transport cause.',
);

// Existing targets, including directories and symlinks, must survive without network activity.
$transport = new FakeHttpTransport([]);
$client = makeHttpDownloadClient($transport);
file_put_contents($target, 'preexisting');
expectUpdateFailure(static fn () => $client->downloadTo($target), 'Existing target must be rejected.');
expect(file_get_contents($target) === 'preexisting', 'Existing target must not be changed or removed.');
unlink($target);
mkdir($target);
expectUpdateFailure(static fn () => $client->downloadTo($target), 'Existing directory must be rejected.');
expect(is_dir($target), 'Existing directory must remain intact.');
rmdir($target);

$foreignPath = $tempRoot . '/foreign.txt';
file_put_contents($foreignPath, 'foreign');
if (function_exists('symlink') && @symlink($foreignPath, $target)) {
    expectUpdateFailure(static fn () => $client->downloadTo($target), 'Live symlink target must be rejected.');
    expect(is_link($target), 'Live symlink must not be removed.');
    expect(file_get_contents($foreignPath) === 'foreign', 'Live symlink referent must remain intact.');
    unlink($target);

    symlink($tempRoot . '/missing-referent', $target);
    expectUpdateFailure(static fn () => $client->downloadTo($target), 'Dangling symlink target must be rejected.');
    expect(is_link($target), 'Dangling symlink must not be removed.');
    unlink($target);
} else {
    echo "Symlink target checks SKIPPED (symlink creation unavailable)\n";
}
expect($transport->requests === [], 'Rejected existing paths must not make a request.');

// A custom transport reporting no ownership must not cause deletion of another actor's file.
$unownedTransport = new class implements HttpTransportInterface {
    /**
     * Create foreign-looking bytes while explicitly denying request ownership of the target.
     *
     * @param string $method HTTP method accepted by the fixture.
     * @param string $url URL recorded only by the surrounding policy client; no network is used.
     * @param list<string> $headers Request headers, unused by this ownership scenario.
     * @param int $timeoutSeconds Transport timeout, unused by this immediate response.
     * @param string|null $targetPath Download target required for the first archive operation.
     * @param string|null $requestBody Request body, unused by this fixture.
     * @return HttpResponse Successful response with targetCreated=false despite a file at the target path.
     * @throws RuntimeException If the adapter incorrectly continues to a checksum request without a target.
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        int $timeoutSeconds,
        ?string $targetPath = null,
        ?string $requestBody = null,
    ): HttpResponse {
        if ($targetPath === null) {
            throw new RuntimeException('Unowned target must be rejected before checksum retrieval.');
        }
        file_put_contents($targetPath, 'unowned');
        return new HttpResponse(200, [], bytes: 7, targetCreated: false);
    }
};
$client = makeHttpDownloadClient($unownedTransport);
expectUpdateFailure(static fn () => $client->downloadTo($target), 'Unowned successful target must be rejected.');
expect(file_get_contents($target) === 'unowned', 'Unowned target must not be deleted.');
unlink($target);

// Failure while fetching a checksum must not remove a file swapped in after the archive response.
$movedPath = $tempRoot . '/owned-moved.tar.gz';
$replacementTransport = new class ($target, $movedPath) implements HttpTransportInterface {
    /**
     * Retain the two paths needed to simulate replacement between download and checksum validation.
     *
     * @param string $target Original archive path later replaced by a foreign file.
     * @param string $moved Path used to preserve the original owned inode during the simulated swap.
     */
    public function __construct(
        /** Original archive path whose directory entry is replaced after download. */
        private string $target,
        /** Alternate path retaining the originally downloaded file during the swap. */
        private string $moved
    ) {
    }

    /**
     * Download owned bytes, then swap the path and fail the separate checksum response.
     *
     * @param string $method Requested HTTP method; scenario selection is based on targetPath instead.
     * @param string $url Requested URL; no network connection is made.
     * @param list<string> $headers Outgoing headers, unused by this ownership scenario.
     * @param int $timeoutSeconds Accepted timeout; the fixture responds immediately.
     * @param string|null $targetPath Archive destination, or null for the subsequent checksum request.
     * @param string|null $requestBody Optional body, unused by this fixture.
     * @return HttpResponse Owned archive response first; HTTP 401 after replacing the target on checksum lookup.
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        int $timeoutSeconds,
        ?string $targetPath = null,
        ?string $requestBody = null,
    ): HttpResponse {
        if ($targetPath !== null) {
            file_put_contents($targetPath, 'owned');
            return new HttpResponse(200, [], bytes: 5, targetCreated: true);
        }

        rename($this->target, $this->moved);
        file_put_contents($this->target, 'replacement');
        return new HttpResponse(401, []);
    }
};
$client = makeHttpDownloadClient($replacementTransport);
expectUpdateFailure(static fn () => $client->downloadTo($target), 'Checksum failure after target swap must fail.');
expect(file_get_contents($target) === 'replacement', 'Cleanup must preserve a replacement with a new identity.');
unlink($target);
unlink($movedPath);
unlink($foreignPath);
@rmdir($tempRoot);

echo "MaxMindHttpClientTest OK\n";
