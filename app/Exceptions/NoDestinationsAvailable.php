<?php

namespace App\Exceptions;

use RuntimeException;

/** Nothing can be planned for these dates and preferences: every place is closed, hidden or out of range. */
class NoDestinationsAvailable extends RuntimeException
{
    public function __construct(string $message = 'No accredited destinations are available to build an itinerary.')
    {
        parent::__construct($message);
    }
}
