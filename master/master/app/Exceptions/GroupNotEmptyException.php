<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a group still contains domains. The message is shown to the admin as-is.
 */
class GroupNotEmptyException extends RuntimeException
{
}
