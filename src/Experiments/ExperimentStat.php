<?php

declare(strict_types=1);

namespace Magna\Pages\Experiments;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $experiment
 * @property string $variant
 * @property int $exposures
 * @property int $conversions
 */
class ExperimentStat extends Model
{
    use HasUlids;

    protected $table = 'pages_experiment_stats';

    protected $fillable = ['experiment', 'variant', 'exposures', 'conversions'];

    /** Conversion rate as a percentage, 0 when nobody has seen it. */
    public function rate(): float
    {
        return $this->exposures === 0 ? 0.0 : round($this->conversions / $this->exposures * 100, 2);
    }
}
