<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Core\Model;

final class RuntimeRaceModel extends Model
{
    protected static string $table = 'phase20_model_race';
}
