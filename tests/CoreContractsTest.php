<?php

/**
 * Exercise IP classification and core lookup outcomes with an in-memory provider.
 *
 * Covers invalid/non-public input, partial location data, not-found results and
 * preservation of caller keys when locating several addresses.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use GeoLocator\Contract\GeoProviderInterface;
use GeoLocator\GeoLocator;
use GeoLocator\Ip\IpAddress;
use GeoLocator\Ip\IpType;
use GeoLocator\Result\GeoLocation;
use GeoLocator\Result\GeoStatus;
use GeoLocator\Result\ProviderResult;

expect(IpAddress::tryFrom('not-an-ip') === null, 'Invalid IP must return null.');
expect(IpAddress::from('192.168.1.20')->type() === IpType::PRIVATE, 'RFC1918 must be private.');
expect(IpAddress::from('127.0.0.1')->type() === IpType::LOOPBACK, '127/8 must be loopback.');
expect(IpAddress::from('169.254.10.2')->type() === IpType::LINK_LOCAL, '169.254/16 must be link-local.');
expect(IpAddress::from('::1')->type() === IpType::LOOPBACK, '::1 must be loopback.');
expect(IpAddress::from('fc00::1')->type() === IpType::PRIVATE, 'fc00::/7 must be private.');
expect(IpAddress::from('8.8.8.8')->isPublic(), '8.8.8.8 must be public.');

$provider = new class implements GeoProviderInterface {
    /**
     * Return a partial location only for the fixture's known public address.
     *
     * @param IpAddress $ip Validated address supplied by the core locator.
     * @return ProviderResult A US location for 8.8.8.8, otherwise NOT_FOUND.
     */
    public function locate(IpAddress $ip): ProviderResult
    {
        if ($ip->value() === '8.8.8.8') {
            return ProviderResult::found(
                new GeoLocation(countryCode: 'US', city: null),
                'test',
            );
        }

        return ProviderResult::notFound('test');
    }
};

$locator = new GeoLocator($provider);

$invalid = $locator->locate('x');
expect($invalid->status === GeoStatus::INVALID_IP, 'Invalid input status mismatch.');

$private = $locator->locate('192.168.0.1');
expect($private->status === GeoStatus::NON_PUBLIC_IP, 'Private IP status mismatch.');

$ok = $locator->locate('8.8.8.8');
expect($ok->status === GeoStatus::OK, 'Public lookup should be OK.');
expect($ok->location?->countryCode === 'US', 'Country mismatch.');
expect($ok->location?->city === null, 'Partial locations must allow null fields.');

$notFound = $locator->locate('1.1.1.1');
expect($notFound->status === GeoStatus::NOT_FOUND, 'Not-found status mismatch.');

$batch = $locator->locateMany([
    'good' => '8.8.8.8',
    'bad' => 'bad',
    'private' => '10.0.0.1',
]);
expect(array_keys($batch) === ['good', 'bad', 'private'], 'Batch must preserve keys.');
expect($batch['bad']->status === GeoStatus::INVALID_IP, 'Batch invalid IP mismatch.');

echo "CoreContractsTest OK\n";
