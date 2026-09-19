<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| RideMate domain configuration
|--------------------------------------------------------------------------
|
| Everything here is a policy number rather than an implementation detail, so
| it lives in configuration instead of being spelled out at a call site. Two
| reasons, and the second is the one that matters.
|
| First, the obvious one: an operator can change a lifetime without a deploy.
|
| Second: tests resolve these values through config() rather than repeating
| them. A test that hard-codes "900" passes for the wrong reason the day the
| access lifetime changes — it asserts the number the author remembered, not
| the number the application uses. The exceptions are the handful of tests
| that deliberately probe a boundary; those set the config value explicitly
| and then assert against it, which is still not a hard-coded duration.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication lifetimes
    |--------------------------------------------------------------------------
    |
    | Three independent clocks, all in seconds:
    |
    |   access_ttl           how long one access token is accepted.
    |   refresh_inactivity   how long a refresh generation stays exchangeable.
    |                        Rotation issues a fresh one, so in practice this
    |                        is "how long may a member be away before signing
    |                        in again".
    |   session_absolute_ttl the ceiling. Rotation cannot extend a session past
    |                        this, so a token family has a definite end even if
    |                        it is refreshed forever.
    |
    | token_retention_tail is a SECURITY parameter, not housekeeping. Reuse of
    | a rotated refresh token is detectable only while that generation's row
    | still exists, so pruning earlier silently narrows the detection window.
    | See App\Auth\TokenService.
    |
    */

    'auth' => [
        'access_ttl' => (int) env('RIDEMATE_ACCESS_TTL', 15 * 60),
        'refresh_inactivity' => (int) env('RIDEMATE_REFRESH_TTL', 30 * 86400),
        'session_absolute_ttl' => (int) env('RIDEMATE_SESSION_TTL', 90 * 86400),
        'token_retention_tail' => (int) env('RIDEMATE_TOKEN_RETENTION', 30 * 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | One-time passcodes
    |--------------------------------------------------------------------------
    |
    | A six-digit code is a 10^6 space, which is only safe because max_attempts
    | and ttl bound it: five guesses inside five minutes. Raising either without
    | raising length weakens the credential.
    |
    | ONE POLICY FOR EVERY DELIVERED-CODE CHANNEL
    |
    | These numbers are not per channel. A passcode delivered by email is the
    | same credential with the same 10^6 space as one delivered by SMS, so it
    | gets the same lifetime, the same attempt ceiling and the same issuance
    | budget. `OtpService` counts them per `(channel, destination)` pair, so the
    | two channels are ISOLATED from each other while sharing one policy —
    | email traffic cannot spend a member's SMS allowance, and neither can be
    | loosened by loosening the other.
    |
    | `max_per_destination_per_hour` was `max_per_phone_per_hour` while SMS was
    | the only channel that existed. Same number, same behaviour; the name
    | stopped being true the moment an email destination could be counted by
    | it. None of these keys reads an environment variable, so the rename is
    | not a deployment concern.
    |
    */

    'otp' => [
        'length' => 6,
        'ttl' => 300,
        'max_attempts' => 5,
        'resend_cooldown' => 60,
        'max_per_destination_per_hour' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pre-account registration
    |--------------------------------------------------------------------------
    |
    | How long a registration — and therefore its credential, which has no clock
    | of its own — may still be advanced, in seconds.
    |
    | Derived from the passcode numbers above rather than picked freely. A
    | mature registration proves two destinations, each behind a five-minute
    | code with a sixty-second resend cooldown. Half an hour leaves a member
    | room to fumble a code on both channels and ask again a few times, and is
    | short enough that an abandoned attempt stops holding an email address and
    | a phone number within the hour.
    |
    | This is the registration's LIFETIME, not a retention period. How long an
    | abandoned row may be KEPT is a separate question, and it has not been
    | answered — see docs/architecture.md.
    |
    | No environment key reads it, like the passcode numbers beside it: it is a
    | policy value tests resolve through config() rather than repeat.
    |
    */

    'registration' => [
        'ttl' => 1800,
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-IP rate limits
    |--------------------------------------------------------------------------
    |
    | These are the COARSE limits, and they are the only ones that live in a
    | cache. The limits that actually protect a member — one live challenge per
    | number, a sixty-second resend cooldown, five verification attempts — are
    | counted from otp_challenges rows instead, because those need to be exact
    | and to survive a restart.
    |
    | An IP address is a poor identity: it is shared by everyone behind one
    | mobile carrier NAT and changed at will by anyone who cares. So these are
    | set generously. They exist to blunt a crude flood, not to be the security
    | boundary, and treating them as the boundary is how a per-IP limit ends up
    | locking out an entire city block.
    |
    | Never persisted: the throttle key is derived at request time and lives
    | only in the cache row, which expires on its own.
    |
    */

    'rate_limits' => [
        'otp_request_per_ip_per_hour' => (int) env('RIDEMATE_LIMIT_OTP_REQUEST', 20),
        'otp_verify_per_ip_per_hour' => (int) env('RIDEMATE_LIMIT_OTP_VERIFY', 10),
        'refresh_per_ip_per_hour' => (int) env('RIDEMATE_LIMIT_REFRESH', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Passcode delivery
    |--------------------------------------------------------------------------
    |
    | `null` is the default ON PURPOSE, and it throws. RideMate has not selected
    | a production SMS provider, and the alternative default — a sender that
    | discards quietly — produces a deployment where sign-in appears to work,
    | no member receives anything, and nothing is logged because as far as the
    | code is concerned nothing failed.
    |
    | `local_echo` is for development and refuses to construct outside the
    | local environment. Tests bind their own in-memory double and never touch
    | either of these.
    |
    | Production sign-in is not operational until an SMS adapter is configured.
    |
    */

    'sms' => [
        'driver' => env('RIDEMATE_SMS_DRIVER', 'null'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email delivery
    |--------------------------------------------------------------------------
    |
    | `null` is the only supported value, and it throws. RideMate has selected
    | no email provider, and this seam exists so that selecting one later is a
    | configuration change rather than a redesign in the middle of an
    | authentication flow.
    |
    | There is an internal application capability that issues and verifies an
    | email passcode, and it is not reachable from outside: no route resolves
    | it, no controller calls it, and an account still has no email address. A
    | configured sender would make delivery work, not make a feature exist.
    | This key configures delivery.
    |
    | Laravel's `config/mail.php` is framework skeleton this application never
    | reads. It is not RideMate email delivery, and this driver is deliberately
    | not a wrapper around it — `MAIL_MAILER` defaults to the log, which is the
    | last place a passcode may go.
    |
    | There is no `local_echo` counterpart. Nothing a developer uses issues an
    | email passcode, and an echo file would have to write a full address and a
    | live code to disk — a phone number can be truncated to its last four
    | digits, and an address has no equivalent safe half.
    |
    */

    'email' => [
        'driver' => env('RIDEMATE_EMAIL_DRIVER', 'null'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Phone numbers
    |--------------------------------------------------------------------------
    |
    | The region used to interpret a number typed without a country code. The
    | pilot is Istanbul, so a member typing 0532... means +90532....
    |
    | This is a PARSING default, never a restriction: a number given in full
    | international form is honoured whatever its country. RideMate ships an
    | English locale and declares Arabic, and a tester with a foreign SIM must
    | not be turned away by a normalization rule.
    |
    */

    'phone' => [
        'default_region' => env('RIDEMATE_PHONE_REGION', 'TR'),
    ],

    /*
    |--------------------------------------------------------------------------
    | The pilot
    |--------------------------------------------------------------------------
    |
    | RideMate's first deployment is one invite-only city, and one thing about
    | it is load-bearing rather than cosmetic: the timezone a published journey
    | is read in.
    |
    | A route carries a wall-clock departure — 08:00 — not an instant, because a
    | weekday-recurring journey has no single instant to store. Deciding whether
    | such a departure is still in the future needs a zone, and taking that zone
    | from the request would let a client move its own deadline. So it is
    | resolved here, once, and every route records the name it was published
    | under, so a second city cannot silently reinterpret the first one's rows.
    |
    | An IANA name rather than a fixed offset: Türkiye does not observe DST
    | today, and a name stays correct if that ever changes, where '+03:00' would
    | quietly begin to lie.
    |
    */

    'pilot' => [
        'timezone' => env('RIDEMATE_PILOT_TIMEZONE', 'Europe/Istanbul'),
    ],

];
