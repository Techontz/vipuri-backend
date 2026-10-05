<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A counter sale asked for something the seller's role does not allow —
 * a changed price or a discount without `pos.discount`. Answered with 403.
 */
class PosPermissionException extends RuntimeException {}
