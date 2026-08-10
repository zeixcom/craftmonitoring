<?php

/**
 * Framework-free regression check: php tests/system-update-tracker-check.php
 *
 * Covers the parts of SystemUpdateTracker that don't need a booted Craft.
 */

declare(strict_types=1);

date_default_timezone_set('America/Los_Angeles');

require __DIR__ . '/../src/services/SystemUpdateTracker.php';

$src = (string) file_get_contents(__DIR__ . '/../src/services/SystemUpdateTracker.php');

// Naive DB datetimes are UTC; without the explicit zone this becomes 05:43:37-07:00.
assert(
    (new DateTimeImmutable('2026-08-10 05:43:37', new DateTimeZone('UTC')))->format(DATE_ATOM)
    === '2026-08-10T05:43:37+00:00'
);
assert(str_contains($src, "new DateTimeZone('UTC')"));

// lastSystemOrPluginUpdateAt must never be stamped with the request time.
assert(!str_contains($src, 'new DateTimeImmutable()'));

$laterOf = (new ReflectionMethod(zeixcom\craftmonitoring\services\SystemUpdateTracker::class, 'laterOf'));
$laterOf->setAccessible(true);
$tracker = new zeixcom\craftmonitoring\services\SystemUpdateTracker();
$later = static fn(?string $a, ?string $b): ?string => $laterOf->invoke($tracker, $a, $b);

assert($later(null, null) === null);
assert($later(null, '2026-08-10T05:43:37+00:00') === '2026-08-10T05:43:37+00:00');
assert($later('2026-08-10T05:43:37+00:00', null) === '2026-08-10T05:43:37+00:00');

// Offsets must be compared as instants, not as wall-clock strings.
assert($later('2026-08-10T07:43:37+02:00', '2026-08-10T05:43:36+00:00') === '2026-08-10T07:43:37+02:00');

// High-water mark: a stale fallback can never push the stored value backwards.
$stored = '2026-08-10T05:43:37+00:00';
assert($later($stored, $later('2026-08-06T10:50:05+00:00', null)) === $stored);

echo "ok\n";
