<?php

namespace App\Services\Knowledge;

/** Internal signal: the shared brain could not be used; message = reason code (no secrets). */
final class BrainUnavailable extends \RuntimeException {}
