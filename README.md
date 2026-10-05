# Rate Limit

Sliding-window rate limiting with atomic counters. APCu, file and in-memory stores, standard headers, and an API that makes forgetting the identity key impossible. Zero dependencies.

## Features

- 🎯 **Identity is mandatory** — the API refuses a limit with nothing to count against.
- 📉 **Sliding window** — no double-allowance burst across the window boundary.
- ⚛️ **Atomic counters** — no read-then-write race, which is exactly the load a limiter exists for.
- 🗄️ **Pluggable stores** — APCu, file, memory; anything else is one interface.
- 📤 **Standard headers** — `X-RateLimit-*` and `Retry-After`.
- 🔒 **Identities are hashed** — emails and IPs never become cache keys or filenames.

## Requirements

PHP 8.5+. `ext-apcu` recommended.

## Installation

```bash
composer require sugarcommerce/rate-limit
```

## Usage

```php
use SugarCommerce\RateLimit\{RateLimiter, Stores\Apcu};

$limiter  = new RateLimiter( new Apcu() );
$decision = $limiter->for( 'login', $ip )->check( 5, 60 );

if ( $decision->denied() ) {
    http_response_code( 429 );

    foreach ( $decision->headers() as $name => $value ) {
        header( "$name: $value" );
    }

    exit;
}
```

Clear the counter when the thing being protected succeeds, so someone who mistyped their password four times isn't left one attempt from a lockout:

```php
$limiter->for( 'login', $ip )->clear( 60 );
```

## The mistake this API prevents

```php
$limiter->check( 'login', 5, 60 );          // one counter for the whole internet
$limiter->for( 'login', $ip )->check( 5, 60 );   // one per visitor
```

The first shares a single counter across every visitor, so five attempts from anyone locks out everybody. An attacker denies your entire customer base by hammering the endpoint from one machine. It reads like rate limiting and is closer to a self-inflicted outage — which is why `for()` requires the identity rather than accepting it optionally.

**Key by the thing being protected, not just the network.** IP is right for "requests to this endpoint". For "magic links sent to this address", key on the address — otherwise one mailbox can be flooded from a botnet, and an office behind one NAT gets blocked as a group. Often you want both:

```php
$limiter->for( 'magic-link:ip', $ip )->check( 10, 3600 );
$limiter->for( 'magic-link:email', $email )->check( 3, 3600 );
```

## Named rules

An application's limits in one table, so a call site names the action and the numbers live in one place:

```php
use SugarCommerce\RateLimit\{RateLimiter, Rules};

$rules = new Rules( RateLimiter::auto( __DIR__ . '/var/limits' ), array(
    'login'           => array( 10, 900 ),    // [ attempts, seconds ]
    'magic-link:mail' => array( 3, 3600 ),
) );

$decision = $rules->check( 'login', $ip );    // counts

if ( $decision->denied() ) {
    return new Response( 429, '', $decision->headers() );
}

$rules->allows( 'token.miss', $ip );          // looks, without counting
```

An action with no rule, or an empty key, is never limited: the answer is `Decision::unlimited()`, allowed and with no headers. A typo in an action name should not take an endpoint down, and every caller with nothing to count against sharing one `''` counter would let any of them lock out the rest. `has()` is there for a test that proves every action you use has a rule.

`RateLimiter::auto( $directory )` picks the APCu store when APCu is enabled **in this process**, and the file store otherwise. Checking `extension_loaded( 'apcu' )` or `apc.enabled` is the common mistake: both are true on a command line where `apc.enable_cli` is off, the APCu store throws, and every test run and cron job dies on the servers that have APCu for their web workers.

## Why sliding window

A fixed window resets on a clock boundary, so the full allowance can be spent at 0:59 and again at 1:01 — double the limit across two seconds, which is precisely the burst you were trying to stop.

This weights the previous window by how much of it is still in view. Ten seconds into a sixty-second window, fifty seconds of the previous one still fall inside the trailing minute, so it counts for 50/60. Same storage cost, no boundary hole.

## Stores

| Store | Shared across | Notes |
|---|---|---|
| `Apcu` | all workers on one server | Fast, atomic in the extension. The default choice. |
| `File` | all workers on one server | No extension needed. Touches disk per request; call `prune()` from cron. |
| `Memory` | one process | Tests, and long-running workers. Useless for FPM — each worker gets its own counters. |

Behind a load balancer, per-machine stores mean the effective limit multiplies by the server count. Implement `Store` against Redis or your database when that matters — it's three methods, and the only requirement is that `increment()` is atomic.

## Testing

```bash
composer install
composer test
```

47 tests. Includes an explicit boundary-burst test proving a fixed-window implementation would let double the allowance through, named rules with their never-limited cases, and the store `auto()` picks.

## License

GPL-2.0-or-later
