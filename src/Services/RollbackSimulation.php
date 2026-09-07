<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Signal-Exception, um einen Probelauf der Zuteilung sauber zurückzurollen. */
final class RollbackSimulation extends RuntimeException
{
}
