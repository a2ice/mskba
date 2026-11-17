<?php

namespace App\Modules\Content\Domain\Models;

use App\Modules\Audit\Domain\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'sort_order'])]
final class FaqSection extends Model
{
    use Auditable;
}
