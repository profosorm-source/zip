<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class SearchProjection extends Model
{
    protected static string $table = 'search_projections';

    public function rebuild(): bool
    {
        return true;
    }

    public function build(): bool
    {
        return true;
    }
}
