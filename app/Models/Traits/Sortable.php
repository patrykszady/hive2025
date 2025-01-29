<?php

namespace App\Models\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Lottery;

trait Sortable
{
    public static function bootSortable()
    {
        static::addGlobalScope(function ($query) {
            return $query->orderBy('order');
        });

        static::creating(function ($model) {
            if (static::sortable($model)->count() === 0) {
                $model->order = 0;
            } else {
                $model->order = static::sortable($model)->max('order') + 1;
            }
        });

        static::deleting(function ($model) {
            $model->displace();
        });
    }

    public function move($position)
    {
        // Lottery::odds(2, outOf: 100)
        //     ->winner(fn () => $this->arrange())
        //     ->choose();

        DB::transaction(function () use ($position) {
            $current = $this->order;
            $after = $position;

            //If there was no position change, dont shift
            if ($current === $after) {
                return;
            }

            // move the target todo out of the position stack
            $this->update(['order' => -1]);

            //Grab the shifted block and shift it up or down
            $block = static::sortable($this)->whereBetween('order', [
                min($current, $after),
                max($current, $after),
            ]);

            $needToShiftBlockBecauseDraggingTargetDown = $current < $after;

            $needToShiftBlockBecauseDraggingTargetDown
                ? $block->decrement('order')
                : $block->increment('order');

            //place target back in position stack
            $this->update(['order' => $after]);
        });
    }

    public function arrange()
    {
        DB::transaction(function () {
            $position = 0;

            foreach (static::sortable($this)->get() as $model) {
                $model->order = $position++;
                $model->save();
            }
        });
    }

    public function displace()
    {
        //999999 = $position. CHANGE!!! because on soft deleted models it stays in the database
        $this->move(999999);
    }
}
