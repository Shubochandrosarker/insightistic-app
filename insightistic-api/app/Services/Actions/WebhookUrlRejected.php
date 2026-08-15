<?php

namespace App\Services\Actions;

use RuntimeException;

/** Raised by WebhookUrlGuard — always a safe, public-facing message, never leaks resolution internals beyond the rejected IP/host. */
class WebhookUrlRejected extends RuntimeException
{
}
