<?php

namespace App\Services\Knowledge;

/** A crawl request was rejected by the SSRF policy (not a network failure). */
class SsrfViolation extends \RuntimeException
{
}
